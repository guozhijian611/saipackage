<?php
namespace Saithink\Saipackage\management;

final class UpgradeServices
{
    public static function assertCompatible(Manager $manager, Services $services, array $snapshot): void
    {
        $id = $snapshot['manifest']['id'];
        $installed = $manager->store->get('installations', $id)['value'] ?? null;
        if (!$installed || $installed['state'] === 'uninstalled') return; // first installs may await configuration
        $required = array_filter($snapshot['manifest']['externalServices'] ?? [], fn ($service) => $service['required'] ?? true);
        if (!$required) return;
        try {
            $values = $manager->settings->raw($id)['values'];
            $result = $services->check($id, $snapshot['manifest'], $installed['schema'], $values);
        } catch (\Throwable) {
            throw new ManagementException('UPDATE_EXTERNAL_SERVICE_UNVERIFIED');
        }
        foreach ($required as $service) {
            $code = $result['results'][$service['id']]['code'] ?? 'UNKNOWN';
            if ($code !== 'OK') throw new ManagementException('UPDATE_EXTERNAL_SERVICE_' . $code);
        }
    }
}
