<?php

declare(strict_types=1);

namespace App\Command;

use App\Administracion\AgendaAdministrativa;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(name: 'zenli:agenda:datos-prueba', description: 'Carga un servicio y un turno ficticios para probar la agenda local.')]
final class CargarDatosPruebaAgenda extends Command
{
    public function __construct(
        private readonly Connection $conexion,
        private readonly AgendaAdministrativa $agenda,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->kernel->getEnvironment() !== 'dev') {
            $io->error('Este comando está disponible únicamente en el entorno de desarrollo.');
            return self::FAILURE;
        }

        $autor = $this->conexion->fetchOne('SELECT id FROM usuarios ORDER BY id LIMIT 1');
        $espacio = $this->conexion->fetchOne('SELECT id FROM recursos WHERE activo = true ORDER BY id LIMIT 1');
        if ($autor === false || $espacio === false) {
            $io->error('Creá una cuenta y un espacio activo antes de cargar los datos de prueba.');
            return self::FAILURE;
        }

        $nombre = 'Servicio ficticio de prueba';
        $servicio = $this->conexion->fetchOne('SELECT id FROM servicios_portada WHERE nombre = ?', [$nombre]);
        if ($servicio === false) {
            $servicio = $this->conexion->fetchOne(
                "INSERT INTO servicios_portada (nombre, descripcion_corta, visible, actualizado_por) VALUES (?, ?, false, ?) RETURNING id",
                [$nombre, 'Datos ficticios para la agenda local', (string) $autor],
            );
        }

        $cliente = 'Cliente ficticio de prueba';
        if ($this->conexion->fetchOne('SELECT id FROM turnos_administrativos WHERE cliente_nombre = ? LIMIT 1', [$cliente]) !== false) {
            $io->success('Los datos ficticios ya fueron cargados.');
            return self::SUCCESS;
        }

        $this->agenda->cargar([
            'servicio' => (string) $servicio,
            'espacio' => (string) $espacio,
            'profesionales' => [],
            'cliente' => $cliente,
            'inicio' => '2099-07-06T10:00',
            'duracion' => 20,
        ], (string) $autor);

        $io->success('Servicio y turno ficticios cargados para el 06/07/2099 a las 10:00.');
        return self::SUCCESS;
    }
}
