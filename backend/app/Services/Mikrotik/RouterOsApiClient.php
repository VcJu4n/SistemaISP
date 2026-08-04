<?php

namespace App\Services\Mikrotik;

use App\Models\MikrotikRouter;
use RuntimeException;

class RouterOsApiClient
{
    /**
     * @var resource|null
     */
    private $stream = null;

    public function testConnection(MikrotikRouter $router): void
    {
        $this->withAuthenticatedConnection($router, fn () => null);
    }

    /**
     * @param  list<string>  $words
     */
    public function executeCommand(MikrotikRouter $router, array $words): void
    {
        $this->executeCommands($router, [$words]);
    }

    /**
     * @param  list<list<string>>  $commands
     */
    public function executeCommands(MikrotikRouter $router, array $commands): void
    {
        $this->withAuthenticatedConnection($router, function () use ($commands): void {
            foreach ($commands as $words) {
                $this->writeSentence($words);
                $reply = $this->readReply();

                if ($reply['done'] !== true) {
                    throw new RuntimeException($reply['error'] ?? 'RouterOS rechazo el comando.');
                }
            }
        });
    }

    public function findOneId(MikrotikRouter $router, string $path, string $field, string $value): string
    {
        $ids = $this->findIdsWhere($router, $path, [$field => $value]);
        $id = $ids[0] ?? null;

        if (! is_string($id) || $id === '') {
            throw new RuntimeException("No se encontro el registro RouterOS [{$path}] con {$field}={$value}.");
        }

        return $id;
    }

    /**
     * @param  array<string, string>  $conditions
     * @return list<string>
     */
    public function findIdsWhere(MikrotikRouter $router, string $path, array $conditions): array
    {
        $rows = [];

        $this->withAuthenticatedConnection($router, function () use ($path, $conditions, &$rows): void {
            $this->writeSentence([
                "{$path}/print",
                '=.proplist=.id',
                ...array_map(fn (string $field, string $value) => "?{$field}={$value}", array_keys($conditions), $conditions),
            ]);
            $rows = $this->readRowsReply();
        });

        return array_values(array_filter(array_map(
            fn (array $row) => $row['.id'] ?? null,
            $rows
        ), fn ($id) => is_string($id) && $id !== ''));
    }

    /**
     * @param  list<string>  $proplist
     * @return list<array<string, string>>
     */
    public function read(MikrotikRouter $router, string $path, array $proplist = []): array
    {
        $rows = [];

        $this->withAuthenticatedConnection($router, function () use ($path, $proplist, &$rows): void {
            $sentence = ["{$path}/print"];

            if ($proplist !== []) {
                $sentence[] = '=.proplist='.implode(',', $proplist);
            }

            $this->writeSentence($sentence);
            $rows = $this->readRowsReply();
        });

        return $rows;
    }

    private function withAuthenticatedConnection(MikrotikRouter $router, callable $callback): void
    {
        $this->connect($router);

        try {
            $this->loginPlain($router->username, $router->password);
        } catch (RuntimeException $exception) {
            $plainLoginError = $exception;
            $this->disconnect();

            $this->connect($router);

            try {
                $this->loginLegacy($router->username, $router->password);
            } catch (RuntimeException) {
                throw $plainLoginError;
            }
        }

        try {
            $callback();
        } finally {
            $this->disconnect();
        }
    }

    private function connect(MikrotikRouter $router): void
    {
        $scheme = $router->use_ssl ? 'ssl' : 'tcp';
        $target = "{$scheme}://{$router->ip_address}:{$router->api_port}";
        $timeout = (float) config('mikrotik.connection.timeout', 5);
        $context = null;

        if ($router->use_ssl) {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => (bool) config('mikrotik.connection.ssl_verify_peer', false),
                    'verify_peer_name' => (bool) config('mikrotik.connection.ssl_verify_peer', false),
                ],
            ]);
        }

        $errorCode = 0;
        $errorMessage = '';
        $stream = @stream_socket_client($target, $errorCode, $errorMessage, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (! $stream) {
            $message = $errorMessage !== '' ? $errorMessage : "No se pudo abrir conexion hacia {$router->ip_address}:{$router->api_port}.";

            throw new RuntimeException($message);
        }

        stream_set_timeout($stream, (int) ceil($timeout));
        $this->stream = $stream;
    }

    private function loginPlain(string $username, string $password): void
    {
        $this->writeSentence(['/login', '=name='.$username, '=password='.$password]);
        $reply = $this->readReply();

        if ($reply['done'] === true && ! isset($reply['attributes']['ret'])) {
            return;
        }

        throw new RuntimeException($reply['error'] ?? 'Credenciales MikroTik invalidas o respuesta API no reconocida.');
    }

    private function loginLegacy(string $username, string $password): void
    {
        $this->writeSentence(['/login']);
        $challenge = $this->readReply();
        $token = $challenge['attributes']['ret'] ?? null;

        if ($challenge['done'] !== true || ! is_string($token) || $token === '') {
            throw new RuntimeException('RouterOS no entrego desafio de autenticacion legacy.');
        }

        $binaryToken = hex2bin($token);

        if ($binaryToken === false) {
            throw new RuntimeException('RouterOS entrego un desafio de autenticacion invalido.');
        }

        $response = '00'.md5(chr(0).$password.$binaryToken);

        $this->writeSentence(['/login', '=name='.$username, '=response='.$response]);
        $reply = $this->readReply();

        if ($reply['done'] === true) {
            return;
        }

        throw new RuntimeException($reply['error'] ?? 'Credenciales MikroTik invalidas.');
    }

    /**
     * @param  list<string>  $words
     */
    private function writeSentence(array $words): void
    {
        foreach ($words as $word) {
            $this->writeWord($word);
        }

        $this->writeLength(0);
    }

    private function writeWord(string $word): void
    {
        $this->writeLength(strlen($word));
        $this->writeBytes($word);
    }

    private function writeLength(int $length): void
    {
        if ($length < 0x80) {
            $encoded = chr($length);
        } elseif ($length < 0x4000) {
            $encoded = chr(($length >> 8) | 0x80).chr($length & 0xFF);
        } elseif ($length < 0x200000) {
            $encoded = chr(($length >> 16) | 0xC0).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        } elseif ($length < 0x10000000) {
            $encoded = chr(($length >> 24) | 0xE0).chr(($length >> 16) & 0xFF).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        } else {
            $encoded = chr(0xF0).chr(($length >> 24) & 0xFF).chr(($length >> 16) & 0xFF).chr(($length >> 8) & 0xFF).chr($length & 0xFF);
        }

        $this->writeBytes($encoded);
    }

    private function writeBytes(string $bytes): void
    {
        if (! is_resource($this->stream)) {
            throw new RuntimeException('La conexion MikroTik no esta abierta.');
        }

        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($this->stream, substr($bytes, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('No se pudo escribir en la conexion MikroTik.');
            }

            $offset += $written;
        }
    }

    /**
     * @return array{done: bool, attributes: array<string, string>, error?: string}
     */
    private function readReply(): array
    {
        $attributes = [];

        while (true) {
            $sentence = $this->readSentence();
            $type = $sentence[0] ?? null;
            $attributes = array_merge($attributes, $this->parseAttributes($sentence));

            if ($type === '!done') {
                return ['done' => true, 'attributes' => $attributes];
            }

            if ($type === '!trap' || $type === '!fatal') {
                return [
                    'done' => false,
                    'attributes' => $attributes,
                    'error' => $attributes['message'] ?? 'RouterOS rechazo la conexion API.',
                ];
            }
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function readRowsReply(): array
    {
        $rows = [];

        while (true) {
            $sentence = $this->readSentence();
            $type = $sentence[0] ?? null;
            $attributes = $this->parseAttributes($sentence);

            if ($type === '!re') {
                $rows[] = $attributes;
                continue;
            }

            if ($type === '!done') {
                return $rows;
            }

            if ($type === '!trap' || $type === '!fatal') {
                throw new RuntimeException($attributes['message'] ?? 'RouterOS rechazo la consulta API.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function readSentence(): array
    {
        $sentence = [];

        while (true) {
            $length = $this->readLength();

            if ($length === 0) {
                return $sentence;
            }

            $sentence[] = $this->readBytes($length);
        }
    }

    private function readLength(): int
    {
        $first = ord($this->readBytes(1));

        if (($first & 0x80) === 0x00) {
            return $first;
        }

        if (($first & 0xC0) === 0x80) {
            return (($first & ~0xC0) << 8) + ord($this->readBytes(1));
        }

        if (($first & 0xE0) === 0xC0) {
            return (($first & ~0xE0) << 16) + (ord($this->readBytes(1)) << 8) + ord($this->readBytes(1));
        }

        if (($first & 0xF0) === 0xE0) {
            return (($first & ~0xF0) << 24)
                + (ord($this->readBytes(1)) << 16)
                + (ord($this->readBytes(1)) << 8)
                + ord($this->readBytes(1));
        }

        return (ord($this->readBytes(1)) << 24)
            + (ord($this->readBytes(1)) << 16)
            + (ord($this->readBytes(1)) << 8)
            + ord($this->readBytes(1));
    }

    private function readBytes(int $length): string
    {
        if (! is_resource($this->stream)) {
            throw new RuntimeException('La conexion MikroTik no esta abierta.');
        }

        $bytes = '';

        while (strlen($bytes) < $length && ! feof($this->stream)) {
            $chunk = fread($this->stream, $length - strlen($bytes));

            if ($chunk === false) {
                throw new RuntimeException('No se pudo leer la respuesta MikroTik.');
            }

            $bytes .= $chunk;
        }

        $metadata = stream_get_meta_data($this->stream);

        if (($metadata['timed_out'] ?? false) || strlen($bytes) !== $length) {
            throw new RuntimeException('Tiempo de espera agotado al leer la respuesta MikroTik.');
        }

        return $bytes;
    }

    /**
     * @param  list<string>  $sentence
     * @return array<string, string>
     */
    private function parseAttributes(array $sentence): array
    {
        $attributes = [];

        foreach ($sentence as $word) {
            if (! str_starts_with($word, '=')) {
                continue;
            }

            [, $name, $value] = array_pad(explode('=', $word, 3), 3, '');
            $attributes[$name] = $value;
        }

        return $attributes;
    }

    private function disconnect(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $this->stream = null;
    }
}
