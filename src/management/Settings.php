<?php
namespace Saithink\Saipackage\management;

use RuntimeException;

final class Settings
{
    public function __construct(private Store $store, private string $key)
    {
    }

    private function key(): string
    {
        $key = base64_decode($this->key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new ManagementException('Configure SAIPACKAGE_SETTINGS_KEY with a stable base64-encoded 32-byte key');
        }
        return $key;
    }

    private function encrypt(array $values): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox(json_encode($values, JSON_THROW_ON_ERROR), $nonce, $this->key()));
    }

    private function decrypt(string $encrypted): array
    {
        $bytes = base64_decode($encrypted, true);
        if ($bytes === false || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) throw new ManagementException('Invalid encrypted settings');
        $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key());
        if ($plain === false) throw new ManagementException('Unable to decrypt settings; restore the original settings key');
        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }

    public function raw(string $id): array
    {
        Manifest::id($id);
        $row = $this->store->get('settings', $id);
        return $row ? ['values' => $this->decrypt($row['value']['encrypted']), 'revision' => $row['revision'], 'version' => $row['value']['version']] : ['values' => [], 'revision' => 0, 'version' => 0];
    }

    public function view(string $id, array $schema): array
    {
        $raw = $this->raw($id);
        $values = $raw['values'];
        $secrets = [];
        foreach ($this->fields($schema) as $path => $field) {
            if (!empty($field['writeOnly'])) {
                $secrets[$path] = ['configured' => $this->value($values, $path) !== null];
                $this->forget($values, $path);
            }
        }
        return ['values' => $values, 'secrets' => $secrets, 'revision' => $raw['revision']];
    }

    public function prepare(string $id, array $schema, array $values, array $actions): array
    {
        $old = $this->raw($id)['values'];
        $fields = $this->fields($schema);
        foreach ($actions as $path => $_) {
            if (empty($fields[$path]['writeOnly'])) throw new ManagementException('Unknown secret field');
        }
        foreach ($fields as $path => $field) {
            if (($schema['ui']['fields'][$path]['scope'] ?? 'instance') !== 'instance') {
                throw new ManagementException('Tenant settings require a host scope adapter');
            }
            if (!empty($field['writeOnly'])) {
                if ($this->value($values, $path) !== null) throw new ManagementException('Use secretActions to edit secrets');
                $action = $actions[$path] ?? ['action' => 'keep'];
                switch ($action['action'] ?? '') {
                    case 'keep':
                        if (($secret = $this->value($old, $path)) !== null) $this->assign($values, $path, $secret);
                        break;
                    case 'replace':
                        if (!is_string($action['value'] ?? null) || $action['value'] === '') throw new ManagementException('Secret replacement must be non-empty');
                        $this->assign($values, $path, $action['value']);
                        break;
                    case 'clear': break;
                    default: throw new ManagementException('Invalid secret action');
                }
            } elseif ($this->value($values, $path) === null && array_key_exists('default', $field)) {
                $this->assign($values, $path, $field['default']);
            }
        }
        if (!Manifest::validateSettings($values, $schema)) throw new ManagementException('Settings do not match schema', 422);
        return $values;
    }

    public function save(string $id, array $schema, int $version, array $values, array $actions, int $expected, int $actor): int
    {
        $before = $this->raw($id);
        if ($before['revision'] !== $expected) throw new ManagementException('Revision conflict', 409);
        $prepared = $this->prepare($id, $schema, $values, $actions);
        $row = $this->store->get('settings', $id);
        $history = $row['value']['history'] ?? [];
        if ($row) $history[] = ['encrypted' => $row['value']['encrypted'], 'version' => $row['value']['version'], 'revision' => $row['revision']];
        $audit = $row['value']['audit'] ?? [];
        $audit[] = ['actor' => $actor, 'at' => time(), 'revision' => $expected + 1, 'fields' => array_keys($this->fields($schema))];
        $fingerprint = $this->fingerprint($prepared, $schema);
        return $this->store->put('settings', $id, [
            'encrypted' => $this->encrypt($prepared), 'version' => $version, 'fingerprint' => $fingerprint,
            'history' => array_slice($history, -5), 'audit' => array_slice($audit, -50),
        ], $expected);
    }

    public function migrate(string $id, array $schema, int $target, ?callable $migration): void
    {
        $old = $this->raw($id);
        if (!$old['revision'] || $old['version'] === $target) return;
        if ($target < $old['version']) throw new ManagementException('Settings version downgrade is not supported');
        $values = $migration ? $migration($old['values'], $old['version'], $target) : $old['values'];
        if (!is_array($values)) throw new ManagementException('Settings migration must return an array');
        $row = $this->store->get('settings', $id);
        $history = $row['value']['history'] ?? [];
        $history[] = ['encrypted' => $row['value']['encrypted'], 'version' => $old['version'], 'revision' => $old['revision']];
        $this->store->put('settings', $id, [
            'encrypted' => $this->encrypt($values), 'version' => $target,
            'fingerprint' => $this->fingerprint($values, $schema), 'history' => array_slice($history, -5), 'audit' => $row['value']['audit'] ?? [],
        ], $old['revision']);
    }

    /** Keyed digest avoids exposing hashes useful for offline guessing of secrets. */
    public function fingerprint(array $values, array $schema): string
    {
        foreach ($this->fields($schema) as $path => $_) {
            if (($schema['ui']['fields'][$path]['connection'] ?? true) === false) $this->forget($values, $path);
        }
        $sort = function (array &$node) use (&$sort): void {
            ksort($node);
            foreach ($node as &$value) if (is_array($value)) $sort($value);
        };
        $sort($values);
        return hash_hmac('sha256', json_encode([$values, $schema], JSON_THROW_ON_ERROR), $this->key());
    }

    public function clear(string $id, int $expected): void
    {
        $this->store->remove('settings', Manifest::id($id), $expected);
        $check = $this->store->get('service_checks', $id);
        if ($check) $this->store->remove('service_checks', $id, $check['revision']);
    }

    public function fields(array $schema, string $prefix = ''): array
    {
        $result = [];
        foreach ($schema['properties'] ?? [] as $name => $field) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name)) throw new ManagementException('Invalid settings field name');
            $path = $prefix . $name;
            if (($field['type'] ?? '') === 'object') $result += $this->fields($field, $path . '.');
            else $result[$path] = $field;
        }
        return $result;
    }

    public function value(array $values, string $path): mixed
    {
        foreach (explode('.', $path) as $part) {
            if (!is_array($values) || !array_key_exists($part, $values)) return null;
            $values = $values[$part];
        }
        return $values;
    }

    private function assign(array &$values, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $last = array_pop($parts);
        foreach ($parts as $part) {
            if (isset($values[$part]) && !is_array($values[$part])) throw new ManagementException('Invalid settings group');
            $values[$part] ??= [];
            $values = &$values[$part];
        }
        $values[$last] = $value;
    }

    private function forget(array &$values, string $path): void
    {
        $parts = explode('.', $path);
        $last = array_pop($parts);
        foreach ($parts as $part) {
            if (!isset($values[$part]) || !is_array($values[$part])) return;
            $values = &$values[$part];
        }
        unset($values[$last]);
    }
}
