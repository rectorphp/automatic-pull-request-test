<?php declare(strict_types=1);

namespace Rector\RectorCI\Controller;

use Github\Client as Github;
use Github\Exception\RuntimeException;
use Github\Exception\ValidationFailedException;
use Nette\Utils\Json;
use Rector\RectorCI\Github\Events\GithubEvent;
use Rector\RectorCI\Github\GithubInstallationAuthenticator;
use Rector\RectorCI\GitRepository\GitRepositoryDownloader;
use Rector\RectorCI\GitRepository\GitRepositoryPathGetter;
use Rector\RectorCI\RectorSet\RectorSetRunner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class GithubWebHookController
{
    /**
     * @var GithubInstallationAuthenticator
     */
    private $githubInstallationAuthenticator;

    /**
     * @var Github
     */
    private $github;

    /**
     * @var RectorSetRunner
     */
    private $rectorSetRunner;

    /**
     * @var GitRepositoryPathGetter
     */
    private $gitRepositoryPathGetter;

    /**
     * @var GitRepositoryDownloader
     */
    private $gitRepositoryDownloader;


    public function __construct(
        Github $github,
        GithubInstallationAuthenticator $githubInstallationAuthenticator,
        RectorSetRunner $rectorSetRunner,
        GitRepositoryPathGetter $gitRepositoryPathGetter,
        GitRepositoryDownloader $gitRepositoryDownloader
    )
    {
        $this->githubInstallationAuthenticator = $githubInstallationAuthenticator;
        $this->github = $github;
        $this->rectorSetRunner = $rectorSetRunner;
        $this->gitRepositoryPathGetter = $gitRepositoryPathGetter;
        $this->gitRepositoryDownloader = $gitRepositoryDownloader;
    }

    /**
     * @Route("/web-hooks/github", methods={"POST"})
     */
    public function __invoke(Request $request): Response
    {
        $event = $request->headers->get('X-Github-Event');

        // @TODO: we should listen for check_run event as well (used when re-running check runs)
        if ($event !== GithubEvent::CHECK_SUITE) {
            return new Response('Non check_suite event', Response::HTTP_ACCEPTED);
        }

        $webhookData = Json::decode((string) $request->getContent());

        if ($webhookData->sender->type === 'Bot') {
            return new Response('Not reacting to commits by bots', Response::HTTP_ACCEPTED);
        }

        // @TODO: Check for requested action, ignore others

        $originalBranch = $webhookData->check_suite->head_branch;
        $newBranch = 'rectified/' . $originalBranch;
        $repositoryFullName = $webhookData->repository->full_name;
        $username = $webhookData->repository->owner->login;
        $repositoryName = $webhookData->repository->name;
        $accessToken = $this->githubInstallationAuthenticator->authenticate($webhookData->installation->id);
        $commitHash = $webhookData->check_suite->head_sha;

        $repositoryDirectory = $this->gitRepositoryPathGetter->get($repositoryFullName);

        $this->gitRepositoryDownloader->prepareRepositoryToCommit($repositoryFullName, $accessToken, $commitHash);

        $rectorProcess = $this->rectorSetRunner->runSetOnDirectory('coding-style', $repositoryDirectory);

        $rectorProcessOutput = Json::decode($rectorProcess->getOutput());
        $blobShas = [];

        // TODO: decide if something was changed or not
        // TODO: if not, skip committing and creating PR

        // 1. Create blobs
        foreach ($rectorProcessOutput->changed_files as $index => $changedFilePath) {
            $blob = $this->github->gitData()->blobs()->create($username, $repositoryName, [
                'content' => file_get_contents($repositoryDirectory . '/' . $changedFilePath),
                'encoding' => 'utf-8',
            ]);

            $blobShas[$changedFilePath] = $blob['sha'];
        }

        // 2. Create tree
        $tree = [];
        foreach ($blobShas as $filePath => $blobSha) {
            $tree[] = [
                'path' => $filePath,
                'mode' => '100644',
                'type' => 'blob',
                'sha' => $blobSha,
            ];
        }

        $tree = $this->github->gitData()->trees()->create($username, $repositoryName, [
            'base_tree' => $webhookData->check_suite->head_commit->tree_id,
            'tree' => $tree,
        ]);

        // 3. Create commit
        $commit = $this->github->gitData()->commits()->create($username, $repositoryName, [
            'message' => 'Rulling the wolrd via Rector!',
            'parents' => [$webhookData->check_suite->head_commit->id],
            'tree' => $tree['sha'],
        ]);

        // 4. Create reference

        try {
            $this->github->gitData()->references()->show($username, $repositoryName, 'heads/' . $newBranch);

            $this->github->gitData()->references()->update($username, $repositoryName, 'heads/' . $newBranch, [
                'force' => true,
                'sha' => $commit['sha'],
            ]);
        } catch (RuntimeException $runtimeException) {
            if ($runtimeException->getCode() !== 404) {
                throw $runtimeException;
            }

            $this->github->gitData()->references()->create($username, $repositoryName, [
                'ref' => 'refs/heads/' . $newBranch,
                'sha' => $commit['sha'],
            ]);
        }

        // 5. Create pull request, it does not exist
        try {
            $this->github->pullRequest()->create($username, $repositoryName, [
                'title' => 'Rector - Fix',
                'head' => $newBranch,
                'base' => $originalBranch,
                'body' => 'Automated pull request by Rector',
            ]);
        } catch (ValidationFailedException $validationFailedException) {
            // PR already exists, it is okay
            if ($validationFailedException->getCode() !== 422) {
                throw $validationFailedException;
            }
        }

        return new Response('OK');
    }
}
