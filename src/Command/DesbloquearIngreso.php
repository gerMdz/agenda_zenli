<?php

declare(strict_types=1);

namespace App\Command;

use App\Administracion\UsuariosAdministrativos;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'zenli:admin:desbloquear', description: 'Quita la demora de ingreso de una cuenta de Zenli.')]
final class DesbloquearIngreso extends Command
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
        try {
            if (!$this->usuarios->desbloquearPorEmail((string) $input->getArgument('email'))) {
                $io->error('No existe una cuenta con ese correo.');

                return self::FAILURE;
            }
        } catch (InvalidArgumentException $error) {
            $io->error($error->getMessage());

            return self::INVALID;
        }
        $io->success('La cuenta puede volver a ingresar.');

        return self::SUCCESS;
    }
}
