<?php

declare(strict_types=1);

namespace App\Command;

use App\Administracion\UsuariosAdministrativos;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'zenli:admin:crear', description: 'Crea una cuenta administradora de Zenli.')]
final class CrearAdministrador extends Command
{
    public function __construct(private readonly UsuariosAdministrativos $usuarios)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Correo de la cuenta');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('Ejecutá el comando en forma interactiva para ingresar la contraseña sin mostrarla.');

            return self::INVALID;
        }

        $clave = $io->askHidden('Contraseña');
        if (!is_string($clave) || $clave !== $io->askHidden('Repetí la contraseña')) {
            $io->error('Las contraseñas no coinciden.');

            return self::INVALID;
        }

        try {
            $this->usuarios->crear((string) $input->getArgument('email'), $clave);
        } catch (InvalidArgumentException $error) {
            $io->error($error->getMessage());

            return self::INVALID;
        } catch (UniqueConstraintViolationException) {
            $io->error('Ya existe una cuenta con ese correo.');

            return self::FAILURE;
        }

        $io->success('Cuenta administradora creada.');

        return self::SUCCESS;
    }
}
