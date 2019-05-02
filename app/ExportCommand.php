<?php

namespace DrunkMonkeyCoding\YouTrackExporter;

use Cog\YouTrack\Rest\Authorizer\TokenAuthorizer;
use Cog\YouTrack\Rest\Client\YouTrackClient;
use Cog\YouTrack\Rest\HttpClient\GuzzleHttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\RequestOptions;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportCommand extends Command
{
    private const OPTIONS_HOST = 'host';
    private const OPTIONS_TOKEN = 'token';
    private const OPTIONS_DIR = 'directory';

    /**
     * @throws InvalidArgumentException
     */
    protected function configure(): void
    {
        $this
            ->setName('youtrack:export')
            ->setDescription('Exports YouTrack Tickets.')
            ->addOption(
                self::OPTIONS_HOST,
                '',
                InputOption::VALUE_REQUIRED,
                'Your YouTrack Host',
                getenv('YOUTRACK_HOST') ?: 'https://youtrack.example.com'
            )->addOption(
                self::OPTIONS_TOKEN,
                't',
                InputOption::VALUE_REQUIRED,
                'Your YouTrack TOKEN',
                getenv('YOUTRACK_TOKEN') ?: 'perm:youtrack-token'
            )->addOption(
                self::OPTIONS_DIR,
                'd',
                InputOption::VALUE_REQUIRED,
                'Your DUMP destination',
                getenv('DEST_DIRECTORY') ?: 'dest/'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return void
     * @throws \Cog\Contracts\YouTrack\Rest\Authenticator\Exceptions\AuthenticationException
     * @throws \Cog\Contracts\YouTrack\Rest\Authorizer\Exceptions\InvalidAuthorizationToken
     * @throws \Cog\Contracts\YouTrack\Rest\Client\Exceptions\ClientException
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws RuntimeException
     */
    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        $token = $input->getOption(self::OPTIONS_TOKEN);
        $destination = rtrim($input->getOption(self::OPTIONS_DIR), '/') . '/';

        $psrHttpClient = new Client([
            'base_uri' => $input->getOption(self::OPTIONS_HOST),
            RequestOptions::VERIFY => false,
            RequestOptions::HEADERS => ['Authorization' => 'Bearer ' . $token],
        ]);

        $httpClient = new GuzzleHttpClient($psrHttpClient);
        $authorizer = new TokenAuthorizer($token);

        $client = new YouTrackClient($httpClient, $authorizer);
        $projects = $client->get('/project/all');
        $iss = [];
        foreach ($projects->toArray() as $project) {
            $issues = $client->get('/issue/byproject/' . $project['shortName'] . '?max=6000');
            $iss = array_merge($iss, $issues->toArray());
        }

        $output->writeln('Total issues: ' . count($iss));
        $attachments = $this->extractAttachments(...$iss);
        $output->writeln('Total attachments: ' . count($attachments));
        $filesDir = $destination . 'files/';
        if (!mkdir($filesDir, 0755, true) && !is_dir($filesDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $filesDir));
        }
        $jsonOptions = 0;
        $jsonOptions |= JSON_UNESCAPED_SLASHES;
        $jsonOptions |= JSON_UNESCAPED_UNICODE;
        $jsonOptions |= JSON_PRETTY_PRINT;
        
        file_put_contents($destination . 'index.json', json_encode($iss, $jsonOptions));
        
        $requests = static function () use ($psrHttpClient, $attachments, $filesDir) {
            foreach ($attachments as $attachment) {
                yield static function () use ($psrHttpClient, $attachment, $filesDir) {
                    
                    $path = $filesDir . $attachment['id'] . '-' . $attachment['value'];
                    return $psrHttpClient->getAsync($attachment['url'], [
                        RequestOptions::SINK => $path
                    ]);
                };
            }
        };

        $pool = new Pool($psrHttpClient, $requests(), [
            'concurrency' => 10,
        ]);

        $pool->promise()->wait();
    }

    /**
     * @param mixed ...$issues
     *
     * @return array
     */
    private function extractAttachments(...$issues): array
    {
        $attachments = [];
        foreach ($issues as $issue) {
            foreach (($issue['field'] ?? []) as $field) {
                if (($field['name'] ?? '') === 'attachments') {
                    $attachments = array_merge($attachments, $field['value']);
                }
            }
        }
        return $attachments;
    }
}
