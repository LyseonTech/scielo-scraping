<?php

namespace ScieloScrapping\Command\Ojs;

use OjsSdk\Providers\Ojs\OjsProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use DAORegistry;
use JournalDAO;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Finder\Finder;

class ImportCommand extends Command
{
    /** @var string */
    protected static $defaultName = 'ojs:import';
    /** @var InputInterface */
    private $input;
    /** @var OutputInterface */
    private $output;
    /** @var string */
    private $outputDirectory;
    /** @var \stdClass */
    private $grid;
    /** @var bool */
    private $doUpgradeGrid = false;

    protected function configure()
    {
        $this
            ->setDescription('Import all to OJS')
            ->addOption('ojs-basedir', null, InputOption::VALUE_REQUIRED, 'Base directory of OJS setup', '/app/ojs')
            ->addOption('journal-path', null, InputOption::VALUE_REQUIRED, 'Journal to import')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory', 'output');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;

        $this->loadOjsBasedir();
        OjsProvider::getApplication();
        $this->saveIssues();
        $this->saveSubmission();
        return Command::SUCCESS;
    }

    private function saveSubmission()
    {
        /**
         * @var SubmissionDAO
         */
        $SubmissionDAO = DAORegistry::getDAO('SubmissionDAO');
        /**
         * @var PublicationDAO
         */
        $PublicationDAO = DAORegistry::getDAO('PublicationDAO');
        // Insert submissions
        $finder = Finder::create()
            ->files()
            ->name('metadata_*.json')
            ->in($this->getOutputDirectory());
        if (!$finder->count()) {
            throw new RuntimeException('Metadata json files not found.');
        }
        foreach ($finder as $file) {
            $article = file_get_contents($file->getRealPath());
            $article = json_decode($article, true);
            if (!$article) {
                continue;
            }

            $update = false;
            if (!$article['ojs']['submissionId']) {
                $update = true;
                /**
                 * @var Submission
                 */
                $submission = $SubmissionDAO->newDataObject();
                $submission->setData('contextId', 1); // Journal = CSP
                $submission->setData('status', STATUS_PUBLISHED);
                $submission->setData('stage_id', WORKFLOW_STAGE_ID_PRODUCTION);
                $submission->setData('dateLastActivity', str_pad($article['updated'], 10, '-01', STR_PAD_RIGHT));
                $submission->setData('dateSubmitted', str_pad($article['published'], 10, '-01', STR_PAD_RIGHT));
                $submission->setData('lastModified', str_pad($article['updated'], 10, '-01', STR_PAD_RIGHT));
                $article['ojs']['submissionId'] = $SubmissionDAO->insertObject($submission);
            }

            if (!$article['ojs']['publicationId']) {
                $update = true;

                list($year, $volume, $issueName) = explode('/', $file->getRelativePath());
                $issue = $this->getIssue($year, $volume, $issueName);

                $publication = $PublicationDAO->newDataObject();
                $publication->setData('submissionId', $article['ojs']['submissionId']);
                $publication->setData('status', 1); // published
                $publication->setData('issueId', $issue['issueId']);
                $publication->setData('locale', $this->identifyPrimaryLanguage($article));
                $publication->setData('pub-id::doi', $article['doi']);
                foreach ($article['title'] as $lang => $title) {
                    $publication->setData('title', $title, $lang);
                }
                foreach ($article['resume'] as $lang => $resume) {
                    $publication->setData('abstract', $resume, $lang);
                }
                // 'disciplines', 'keywords', 'languages', 'subjects', 'supportingAgencies'
                // categoryIds
                $article['ojs']['publicationId'] = $PublicationDAO->insertObject($publication);
        
                $submission->setData('currentPublicationId', $article['ojs']['publicationId']);
                $SubmissionDAO->updateObject($submission);
            }
            if ($update) {
                file_put_contents($file->getRealPath(), json_encode($article));
            }
        }
    }

    private function getIssue($year, $volume, $issueName)
    {
        $this->getGrid()[$year][$volume][$issueName];
    }

    private function saveIssues()
    {
        $journal = $this->getJournal();
        $langs = $journal->getSupportedLocales();
        /**
         * @var IssueDAO
         */
        $issueDAO = DAORegistry::getDAO('IssueDAO');
        $grid = $this->getGrid();
        foreach ($grid as $year => $volumes) {
            foreach ($volumes as $volume => $issues) {
                foreach ($issues as $issueName => $attr) {
                    if (isset($attr['issueId'])) {
                        continue;
                    }
                    $issues = $issueDAO->getIssuesByIdentification($journal->getId(), $volume, $attr['text'], $year);
                    if ($issues->getCount()) {
                        continue;
                    }
                    // Insert issue
                    $issue = $issueDAO->newDataObject();
                    $issue->setJournalId($journal->getId());
                    $issue->setVolume($volume);
                    $issue->setShowVolume(1);
                    $issue->setNumber($attr['text']);
                    $issue->setShowNumber(1);
                    $issue->setYear($year);
                    $issue->setShowYear(1);
                    foreach($langs as $lang) {
                        $issue->setTitle($attr['text'], $lang);
                    }
                    $issue->setShowTitle(1);
                    $issue->setPublished(1);
                    $issueId = $issueDAO->insertObject($issue);
                    $this->setGridAttribute($year, $volume, $issueName, 'issueId', $issueId);
                }
            }
        }
    }

    private function setGridAttribute($year, $volume, $issueName, $attribute, $value)
    {
        $this->doUpgradeGrid = true;
        $this->grid[$year][$volume][$issueName][$attribute] = $value;
    }

    private function loadOjsBasedir()
    {
        $ojsBasedir = $this->input->getOption('ojs-basedir');
        if (!is_dir($ojsBasedir)) {
            $ojsBasedir = getenv('OJS_WEB_BASEDIR');
            if (!$ojsBasedir) {
                throw new RuntimeException('Inform a valid path in ojs-basedir option');
            }
        }
        putenv('OJS_WEB_BASEDIR=' . $ojsBasedir);
    }

    private function getOutputDirectory()
    {
        if (!$this->outputDirectory) {
            $this->outputDirectory = $this->input->getOption('output');
            if (!is_dir($this->outputDirectory)) {
                $this->output->writeln('Run frist scielo download command or fix directory path');
                throw new RuntimeException('Error on create output directory called [' . $this->outputDirectory . ']');
            }
        }
        return $this->outputDirectory;
    }

    private function getGrid()
    {
        if (!$this->grid) {
            $outputDirectory = $this->getOutputDirectory();
            if (!is_file($outputDirectory . '/grid.json')) {
                throw new RuntimeException('grid.json not found');
            }
            $this->grid = file_get_contents($outputDirectory . '/grid.json');
            $this->grid = json_decode($this->grid, true);
            if (!$this->grid) {
                throw new RuntimeException('Invalid content in grid.json</error>');
            }
        }
        return $this->grid;
    }

    private function getJournal()
    {
        /**
         * @var JournalDAO
         */
        $JournalDAO = DAORegistry::getDAO('JournalDAO');
        $journals = $JournalDAO->getAll();
        if (!$journals) {
            throw new RuntimeException('Create a journal in OJS first',);
        }
        while ($journal = $journals->next()) {
            $options[$journal->getPath()] = $journal;
        }
        if (count($options) > 1) {
            $helper = $this->getHelper('question');
            $question = new ChoiceQuestion(
                'Select the destination journal',
                array_keys($options)
            );
            $question->setErrorMessage('Journal path %s is invalid.');
            $journalPath = $helper->ask($this->input, $this->output, $question);
            return $journals[array_keys($options)[$journalPath]];
        }
        return current($options);
    }

    private function identifyPrimaryLanguage($article)
    {
        if (isset($article['formats']['text'])) {
            if (count($article['formats']['text']) == 1) {
                return array_key_first($article['formats']['text']);
            }
        }
        if (isset($article['formats']['pdf'])) {
            if (count($article['formats']['pdf']) == 1) {
                return array_key_first($article['formats']['pdf']);
            }
        }
        if (isset($article['title'])) {
            if (count($article['title']) == 1) {
                return array_key_first($article['title']);
            }
        }
        if (isset($article['keywords'])) {
            if (count($article['keywords']) == 1) {
                return array_key_first($article['keywords']);
            }
        }
    }

    private function __destruct()
    {
        if ($this->doUpgradeGrid) {
            file_put_contents($this->getOutputDirectory() . '/grid.json', json_encode($this->getGrid()));
        }
    }
}