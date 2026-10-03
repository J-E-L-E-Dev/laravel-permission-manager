<?php

namespace Ahinest\LaravelPermissionManager\Services;

use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Ahinest\LaravelPermissionManager\Support\AuditState;
use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Aprovisionamiento privilegiado de infraestructura con políticas vacías explícitas.
 * No debe exponerse como flujo HTTP de usuarios ni ejecutarse automáticamente al arrancar.
 */
class DelegationPolicyProvisioningService
{
    /**
     * Recibe la frontera transaccional que coordina la aplicación del plan con otras escrituras RBAC.
     */
    public function __construct(private readonly RbacWriteTransaction $writes) {}

    /**
     * Planifica o aplica políticas estructurales vacías para roles explícitamente configurados.
     *
     * Es una operación privilegiada de infraestructura, no una entrada HTTP de usuario.
     * El modo dryRun no escribe ni reserva el escritor SQLite. Al aplicar, recalcula bajo
     * lock y evita todo cambio si hay conflictos; nunca copia P a Q ni crea roles.
     * Registra cambios efectivos con actor de infraestructura e invalida caché al terminar.
     *
     * @return array{rows: array, conflicts: bool, applied: bool}
     */
    public function run(bool $dryRun = false): array
    {
        $this->assertInfrastructure();

        // La vista previa no reserva el escritor SQLite ni modifica filas.
        if ($dryRun) {
            return $this->plan();
        }

        return $this->writes->run(function () {
            // Recalcula bajo el lock común HTTP/CLI para no aplicar una vista previa obsoleta.
            $plan = $this->plan();
            if ($plan['conflicts']) {
                return $plan;
            }

            foreach ($plan['rows'] as $row) {
                if ($row['action'] === 'unchanged') {
                    continue;
                }

                $policyId = $row['policy_id'];
                if ($row['action'] === 'create-and-link') {
                    $policy = DelegationPolicy::create([
                        'name' => 'Delegación — '.$row['role'],
                        'slug' => $row['slug'],
                        'active' => true,
                    ]);
                    $policyId = $policy->id;
                    app(AuthorityAuditTrail::class)->record(null, 'delegation_policy', $policyId, 'delegation_policy.created', [], [...AuditState::policy($policy->fresh()), 'permissions' => []]);
                    // No crea membresías de permisos ni modifica Q existente.
                }

                $changed = Models::role()->whereKey($row['role_id'])
                    ->whereNull('delegation_policy_id')
                    ->update(['delegation_policy_id' => $policyId]);
                if ($changed !== 1) {
                    throw new LogicException('Role association changed during provisioning.');
                }
                app(AuthorityAuditTrail::class)->record(null, 'role', $row['role_id'], 'role.delegation_policy.changed', ['delegation_policy_id' => null], ['delegation_policy_id' => (int) $policyId]);
            }

            $plan['applied'] = true;

            return $plan;
        });
    }

    /**
     * Exige tablas, motores y fila de coordinación válidos antes de planificar o aprovisionar.
     */
    private function assertInfrastructure(): void
    {
        app(InfrastructureInspector::class)->assertReady();
    }

    /**
     * Construye filas de acciones y conflictos sin modificar datos.
     *
     * Solo considera nombres configurados, excluye admin y rechaza asociaciones ambiguas
     * o concesiones implícitas al vincular políticas pobladas. applied permanece false.
     */
    private function plan(): array
    {
        $rows = [];
        // Usa solo identidades configuradas, nunca ROLE_PERMISSIONS ni roles descubiertos en la base.
        foreach (config('permission-manager.provisioning', []) as $name) {
            if ($name === config('permission-manager.admin_role')) {
                continue;
            }
            $slug = 'role-'.$name;
            $row = ['role' => $name, 'role_id' => null, 'slug' => $slug, 'policy_id' => null, 'action' => 'conflict', 'reason' => ''];
            if (strlen($slug) > 255 || ! preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $slug)) {
                $row['reason'] = 'Invalid structural slug.';
            } else {
                $roles = Models::role()->where('name', $name)->get();
                $role = $roles->first();
                if ($roles->count() !== 1 || $role->name !== $name || $role->guard_name !== Models::guard()) {
                    $row['reason'] = 'Base role missing, ambiguous or not exact web identity.';
                } else {
                    $row['role_id'] = $role->id;
                    $policy = DelegationPolicy::query()->where('slug', $slug)->first();
                    $row['policy_id'] = $policy?->id;
                    if ($policy && $policy->slug !== $slug) {
                        $row['reason'] = 'Structural slug identity mismatch.';
                    } elseif ($role->delegation_policy_id !== null && (int) $role->delegation_policy_id !== $policy?->id) {
                        $row['reason'] = 'Base role already linked to another policy.';
                    } elseif ($policy && $role->delegation_policy_id === null && $policy->permissions()->exists()) {
                        $row['reason'] = 'Linking a populated policy would grant delegation; associate it through an authorized operation.';
                    } elseif ($policy && $policy->roles()->where('id', '!=', $role->id)->exists()) {
                        $row['reason'] = 'Structural slug is consumed by another role; manual review required.';
                    } elseif ($policy && DB::table('lpm_policy_permission as membership')
                        ->leftJoin(config('permission.table_names.permissions').' as permissions', 'permissions.id', '=', 'membership.permission_id')
                        ->where('membership.delegation_policy_id', $policy->id)
                        ->get(['permissions.guard_name'])->contains(fn ($permission) => $permission->guard_name !== Models::guard())) {
                        $row['reason'] = 'Existing policy contains invalid permission guards; no repair performed.';
                    } else {
                        $row['action'] = $role->delegation_policy_id !== null ? 'unchanged' : ($policy ? 'link-existing' : 'create-and-link');
                    }
                }
            }
            $rows[] = $row;
        }

        return ['rows' => $rows, 'conflicts' => collect($rows)->contains('action', 'conflict'), 'applied' => false];
    }
}
