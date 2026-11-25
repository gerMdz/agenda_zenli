<?php

declare(strict_types=1);

namespace App\Seguridad;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Throwable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final readonly class ClavesAcceso
{
    private const INGRESO = 'zenli.passkey.ingreso';
    private const ALTA = 'zenli.passkey.alta';

    private SerializerInterface $serializador;

    public function __construct(
        private Connection $conexion,
        private ProveedorUsuarios $usuarios,
        private PasswordHasherFactoryInterface $hashers,
        private LimiteIngresos $limite,
        private AuditoriaAccesos $auditoria,
        #[Autowire('%kernel.environment%')] private string $entorno,
        #[Autowire('%env(default:zenli.origen_claves_acceso:ZENLI_ORIGEN_CLAVES_ACCESO)%')] private string $origenConfigurado,
    ) {
        $this->serializador = (new WebauthnSerializerFactory(new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
        ])))->create();
    }

    public function opcionesIngreso(Request $peticion): array
    {
        $origen = $this->origen($peticion);
        $opciones = PublicKeyCredentialRequestOptions::create(
            random_bytes(32), parse_url($origen, PHP_URL_HOST), userVerification: 'required', timeout: 120_000,
        );

        return $this->guardarDesafio($peticion, self::INGRESO, $origen, $opciones);
    }

    public function opcionesAlta(Request $peticion, UsuarioSesion $cuenta, mixed $clave): array
    {
        $origen = $this->origen($peticion);
        $peticion->getSession()->remove(self::ALTA);
        $ip = $peticion->getClientIp();
        $demora = $this->limite->demora($cuenta->email, $ip);
        if ($demora > 0) {
            throw new HttpException(429, 'Demasiados intentos. Probá más tarde.', headers: ['Retry-After' => (string) $demora]);
        }
        if (!is_string($clave) || strlen($clave) > 72 || str_contains($clave, "\0")
            || !$this->hashers->getPasswordHasher(UsuarioSesion::class)->verify($cuenta->getPassword() ?? '', $clave)) {
            $this->limite->registrarFallo($cuenta->email, $ip);
            $this->auditoria->registrar('FALLO', $cuenta->id);
            throw new HttpException(403, 'La contraseña de Zenli es incorrecta.');
        }
        $id = $this->conexion->fetchOne('SELECT identificador_webauthn::text FROM usuarios WHERE id = ?', [$cuenta->id]);
        $excluidas = [];
        foreach ($this->conexion->fetchFirstColumn('SELECT credencial FROM claves_acceso WHERE usuario_id = ?', [$cuenta->id]) as $registro) {
            $excluidas[] = $this->registro($registro)->getPublicKeyCredentialDescriptor();
        }
        $opciones = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create('Agenda Zenli', parse_url($origen, PHP_URL_HOST)),
            PublicKeyCredentialUserEntity::create($cuenta->email, $id, $cuenta->email),
            random_bytes(32),
            [PublicKeyCredentialParameters::create('public-key', -7), PublicKeyCredentialParameters::create('public-key', -257)],
            AuthenticatorSelectionCriteria::create(authenticatorAttachment: 'platform', userVerification: 'required', residentKey: 'required'),
            attestation: 'none', excludeCredentials: $excluidas, timeout: 120_000,
        );

        return $this->guardarDesafio($peticion, self::ALTA, $origen, $opciones, $cuenta->id);
    }

    public function registrar(Request $peticion, UsuarioSesion $cuenta): void
    {
        [$origen, $opciones] = $this->consumirDesafio($peticion, self::ALTA, PublicKeyCredentialCreationOptions::class, $cuenta->id);
        $credencial = $this->credencial($peticion);
        if (!$credencial->response instanceof AuthenticatorAttestationResponse) {
            throw new InvalidArgumentException('Respuesta de alta inválida.');
        }
        $registro = AuthenticatorAttestationResponseValidator::create(self::validadores($origen)->creationCeremony())
            ->check($credencial->response, $opciones, parse_url($origen, PHP_URL_HOST));
        if (!hash_equals($registro->publicKeyCredentialId, $credencial->rawId)) {
            throw new InvalidArgumentException('Identificador de clave inválido.');
        }

        try {
            $this->conexion->transactional(function () use ($cuenta, $registro): void {
                $this->conexion->fetchOne('SELECT id FROM usuarios WHERE id = ? FOR UPDATE', [$cuenta->id]);
                if ((int) $this->conexion->fetchOne('SELECT count(*) FROM claves_acceso WHERE usuario_id = ?', [$cuenta->id]) >= 10) {
                    throw new HttpException(409, 'Ya tenés diez claves de acceso. Quitá una antes de agregar otra.');
                }
                $this->conexion->insert('claves_acceso', [
                    'id' => hash('sha256', $registro->publicKeyCredentialId), 'usuario_id' => $cuenta->id,
                    'credencial' => $this->serializador->serialize($registro, 'json'),
                ]);
                $this->auditoria->registrar('PASSKEY_CREADA', $cuenta->id, $cuenta->id);
            });
        } catch (UniqueConstraintViolationException $error) {
            throw new HttpException(409, 'La clave ya está registrada.', $error);
        }
    }

    public function autenticar(Request $peticion): UsuarioSesion
    {
        [$origen, $opciones] = $this->consumirDesafio($peticion, self::INGRESO, PublicKeyCredentialRequestOptions::class);
        $credencial = $this->credencial($peticion);
        if (!$credencial->response instanceof AuthenticatorAssertionResponse) {
            throw new InvalidArgumentException('Respuesta de ingreso inválida.');
        }

        return $this->conexion->transactional(function () use ($credencial, $opciones, $origen): UsuarioSesion {
            $id = hash('sha256', $credencial->rawId);
            $fila = $this->conexion->fetchAssociative(
                'SELECT c.usuario_id::text, c.credencial, u.email FROM claves_acceso c JOIN usuarios u ON u.id = c.usuario_id WHERE c.id = ? FOR UPDATE OF c',
                [$id],
            );
            if ($fila === false) {
                throw new InvalidArgumentException('Clave desconocida.');
            }
            $cuenta = $this->usuarios->loadUserByIdentifier($fila['email']);
            if ($cuenta->id !== $fila['usuario_id']) {
                throw new UserNotFoundException();
            }

            $registro = AuthenticatorAssertionResponseValidator::create(self::validadores($origen)->requestCeremony())
                ->check($this->registro($fila['credencial']), $credencial->response, $opciones, parse_url($origen, PHP_URL_HOST), null);
            $this->conexion->executeStatement('UPDATE claves_acceso SET credencial = ?, ultimo_uso = now() WHERE id = ?', [
                $this->serializador->serialize($registro, 'json'), $id,
            ]);

            return $cuenta;
        });
    }

    public function listar(string $cuenta): array
    {
        return $this->conexion->fetchAllAssociative(
            'SELECT id, creada_en, ultimo_uso FROM claves_acceso WHERE usuario_id = ? ORDER BY creada_en DESC', [$cuenta],
        );
    }

    public function revocar(string $cuenta, string $id): void
    {
        $this->conexion->transactional(function () use ($cuenta, $id): void {
            if (!$this->conexion->delete('claves_acceso', ['usuario_id' => $cuenta, 'id' => $id])) {
                throw new HttpException(404, 'La clave de acceso no está disponible.');
            }
            $this->auditoria->registrar('PASSKEY_REVOCADA', $cuenta, $cuenta);
        });
    }

    private function credencial(Request $peticion): PublicKeyCredential
    {
        if (strlen($peticion->getContent()) > 65_536) {
            throw new InvalidArgumentException('La respuesta es demasiado grande.');
        }

        try {
            $credencial = $this->serializador->deserialize($peticion->getContent(), PublicKeyCredential::class, 'json');
        } catch (Throwable $error) {
            throw new InvalidArgumentException('Respuesta del autenticador inválida.', previous: $error);
        }
        if ($credencial->response->clientDataJSON->crossOrigin) {
            throw new InvalidArgumentException('La respuesta debe originarse en la agenda.');
        }

        return $credencial;
    }

    private function registro(string $json): CredentialRecord
    {
        return $this->serializador->deserialize($json, CredentialRecord::class, 'json');
    }

    private function guardarDesafio(Request $peticion, string $clave, string $origen, object $opciones, ?string $cuenta = null): array
    {
        $json = $this->serializador->serialize($opciones, 'json');
        $peticion->getSession()->set($clave, ['opciones' => $json, 'origen' => $origen, 'cuenta' => $cuenta, 'vence' => time() + 120]);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function consumirDesafio(Request $peticion, string $clave, string $tipo, ?string $cuenta = null): array
    {
        $desafio = $peticion->getSession()->remove($clave);
        $origen = $this->origen($peticion);
        if ($desafio === null || $desafio['vence'] <= time() || $desafio['origen'] !== $origen || $desafio['cuenta'] !== $cuenta) {
            throw new InvalidArgumentException('La solicitud venció. Volvé a iniciar el proceso.');
        }

        return [$origen, $this->serializador->deserialize($desafio['opciones'], $tipo, 'json')];
    }

    private function origen(Request $peticion): string
    {
        if ($this->entorno === 'dev' && $peticion->getHost() === 'localhost') {
            return $peticion->getSchemeAndHttpHost();
        }
        if (!preg_match('~\Ahttps://[a-z0-9.-]+(?::[0-9]+)?\z~D', $this->origenConfigurado)) {
            throw new HttpException(503, 'El origen de claves de acceso requiere HTTPS.');
        }

        return $this->origenConfigurado;
    }

    private static function validadores(string $origen): CeremonyStepManagerFactory
    {
        $fabrica = new CeremonyStepManagerFactory();
        $fabrica->setAllowedOrigins([$origen]);

        return $fabrica;
    }
}
