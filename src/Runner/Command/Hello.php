<?php

declare(strict_types=1);

namespace Simphle\Action\Runner\Command;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Hello extends Command
{
    protected static string $defaultName = 'hello';

    protected static string $defaultDescription = 'Says hello';

    public function __construct(
        protected ContainerInterface $container
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp('Says hello');
        $this->addArgument('target', InputArgument::REQUIRED, 'Who to greet');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $target = $input->getArgument('target');
            $output->writeln(sprintf("Hello, %s!", $target));
        } catch (RuntimeException $e) {
            $output->writeln($e->getMessage());
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }
}
