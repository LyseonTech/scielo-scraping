<?php

namespace ScieloScrapping\Command\Scielo;

use ScieloScrapping\ScieloClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BaseCommand extends Command
{
    protected $years;
    protected $volumes;
    protected $issues;
    protected $articleId;
    /**
     * @var ScieloClient
     */
    protected $scieloClient;

    protected function configure()
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Slug of journal')
            ->addOption('year', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Year of journal')
            ->addOption('volume', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Volume number')
            ->addOption('issue', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Issue name')
            ->addOption('article', null, InputOption::VALUE_OPTIONAL, 'Article name')
            ->addOption('output', null, InputOption::VALUE_OPTIONAL, 'Output directory', 'output')
            ->addOption('assets', null, InputOption::VALUE_OPTIONAL, 'Assets directory', 'assets');
    }

    protected function setup(InputInterface $input, OutputInterface $output)
    {
        $outputDirectory = $input->getOption('output');
        if (!is_dir($outputDirectory)) {
            if (!mkdir($outputDirectory)) {
                $output->writeln('<error>Error on create output directory called [' . $outputDirectory . ']</error>');
                return Command::FAILURE;
            };
        }

        $assets = $input->getOption('assets');
        if (!is_dir($assets)) {
            $output->writeln('<error>Invalid assets directory</error>');
            return Command::FAILURE;
        }

        $journal = $input->getArgument('slug');
        $this->scieloClient = new ScieloClient([
            'journal_slug' => $journal,
            'base_directory' => $outputDirectory,
            'assets_folder' => $assets
        ]);

        $grid = $this->scieloClient->getGrid();
        if (!$grid) {
            $output->writeln('<error>Error downloading journal grid</error>');
            return Command::FAILURE;
        }

        $this->years = $input->getOption('year');
        $diff = array_diff($this->years, array_keys($grid));
        if ($diff) {
            $output->writeln('<error>Invalid years: ' . implode(', ', $diff) . '</error>');
            return Command::FAILURE;
        }
        if (!$this->years) {
            $this->years = array_keys($grid);
        }

        $this->volumes = $input->getOption('volume');
        $validVolumes = [];
        foreach ($this->years as $year) {
            $validVolumes = array_merge($validVolumes, array_keys($grid[$year]));
        }
        $diff = array_diff($this->volumes, $validVolumes);
        if ($diff) {
            $output->writeln('<error>Invalid volumes: ' . implode(', ', $diff) . '</error>');
            return Command::FAILURE;
        }
        if (!$this->volumes) {
            $this->volumes = $validVolumes;
        }

        $this->issues = $input->getOption('issue') ?: [];
        $validIssues = [];
        foreach ($this->years as $year) {
            foreach ($this->volumes as $volume) {
                if (!isset($grid[$year][$volume])) {
                    continue;
                }
                $validIssues = array_merge($validIssues, array_keys($grid[$year][$volume]));
            }
        }
        $diff = array_diff($this->issues, $validIssues);
        if ($diff) {
            $output->writeln('<error>Invalid issues: ' . implode(', ', $diff) . '</error>');
            return Command::FAILURE;
        }
        if (!$this->issues) {
            $this->issues = $validIssues;
        }

        $this->articleId = $input->getOption('article');
        return Command::SUCCESS;
    }
}
