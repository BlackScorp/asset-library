<?php

namespace Godot\AssetLibrary\Helpers;

class Utils
{
    private $c;

    public function __construct($c)
    {
        $this->c = $c;
    }

    public function getComputedDownloadUrl($repo_url, $provider, $commit, &$warning=null) // i.e. browse_url, download_provider, download_commit
    {
        $repo_url = rtrim($repo_url, '/');
        if (is_int($provider)) {
            $provider = $this->c->constants['download_provider'][$provider];
        }
        $warning_suffix = "Please, ensure that the URL and the repository provider are, indeed, correct.";
        $light_warning_suffix = "Please, doublecheck that the URL and the repository provider are correct.";
        switch ($provider) {
            case 'GitHub':
                if (sizeof(preg_grep('/^https:\/\/github\.com\/[^\/]+?\/[^\/]+?$/', [$repo_url])) == 0) {
                    $warning = "\"$repo_url\" doesn't look correct; it should be similar to \"https://github.com/<owner>/<name>\". $warning_suffix";
                }
                return "$repo_url/archive/$commit.zip";
            case 'GitLab':
                if (sizeof(preg_grep('/^https:\/\/(gitlab\.com|[^\/]+)\/[^\/]+?\/[^\/]+?$/', [$repo_url])) == 0) {
                    $warning = "\"$repo_url\" doesn't look correct; it should be similar to \"https://<gitlab instance>/<owner>/<name>\". $warning_suffix";
                } elseif (sizeof(preg_grep('/^https:\/\/(gitlab\.com)\/[^\/]+?\/[^\/]+?$/', [$repo_url])) == 0) {
                    $warning = "\"$repo_url\" might not be correct; it should be similar to \"https://gitlab.com/<owner>/<name>\", unless the asset is hosted on a custom instance of GitLab. $light_warning_suffix";
                }
                return "$repo_url/repository/archive.zip?ref=$commit";
            case 'BitBucket':
                if (sizeof(preg_grep('/^https:\/\/bitbucket\.org\/[^\/]+?\/[^\/]+?$/', [$repo_url])) == 0) {
                    $warning = "\"$repo_url\" doesn't look correct; it should be similar to \"https://bitbucket.org/<owner>/<name>\". $warning_suffix";
                }
                return "$repo_url/get/$commit.zip";
            case 'Gogs':
                if (sizeof(preg_grep('/^https?:\/\/[^\/]+?\/[^\/]+?\/[^\/]+?$/', [$repo_url])) == 0) {
                    $warning = "\"$repo_url\" doesn't look correct; it should be similar to \"http<s>://<gogs instance>/<owner>/<name>\". $warning_suffix";
                }
                $warning = "Since Gogs might be self-hosted, we can't be sure that \"$repo_url\" is a valid Gogs URL. $light_warning_suffix";
                return "$repo_url/archive/$commit.zip";
            case 'cgit':
                if (sizeof(preg_grep('/^https?:\/\/[^\/]+?\/[^\/]+?\/[^\/]+?$/', [$repo_url])) == 0) {
                    $warning = "\"$repo_url\" doesn't look correct; it should be similar to \"http<s>://<cgit instance>/<owner>/<name>\". $warning_suffix";
                }
                $warning = "Since cgit might be self-hosted, we can't be sure that \"$repo_url\" is a valid cgit URL. $light_warning_suffix";
                return "$repo_url/snapshot/$commit.zip";
            default:
                return "$repo_url/$commit.zip"; // Obviously incorrect, but we would like to have some default case...
        }
    }

    public function getDefaultIssuesUrl($repo_url, $provider) // i.e. browse_url, download_provider
    {
        $repo_url = rtrim($repo_url, '/');
        if (is_int($provider)) {
            $provider = $this->c->constants['download_provider'][$provider];
        }
        switch ($provider) {
            case 'GitHub':
            case 'GitLab':
            case 'BitBucket':
            case 'Gogs':
                return "$repo_url/issues";
            case 'cgit':
            default:
                return "";
        }
    }

    public function errorResponseIfNotUserHasLevel($currentStatus, &$response, $user, $required_level_name, $message = 'You are not authorized to do this')
    {
        if ($user === false || $currentStatus) {
            return true;
        }

        if ((int) $user['type'] < $this->c->constants['user_type'][$required_level_name]) {
            $response = $response->withJson([
                'error' => $message,
            ], 403);
            return true;
        }
        return false;
    }

    public function errorResponseIfMissingOrNotString($currentStatus, &$response, $object, $property)
    {
        if ($currentStatus) {
            return true;
        }

        if (!isset($object[$property]) || !is_string($object[$property]) || $object[$property] == "") {
            $response = $response->withJson([
                'error' => $property . ' is required, and must be a string'
            ], 400);
            return true;
        }
        return false;
    }

    public function errorResponseIfQueryBad($currentStatus, &$response, $query, $message = 'An error occured while executing DB queries')
    {
        if ($currentStatus) {
            return true;
        }

        if ($query->errorCode() != '00000') {
            $this->c->logger->error('DBError', $query->errorInfo());
            $response = $response->withJson([
                'error' => $message,
            ], 500);
            return true;
        }
        return false;
    }

    public function errorResponseIfQueryNoResults($currentStatus, &$response, $query, $message = 'DB returned no results')
    {
        if ($currentStatus) {
            return true;
        }

        if ($query->rowCount() == 0) {
            $response = $response->withJson([
                'error' => $message
            ], 404);
            return true;
        }
        return false;
    }

    public function ensureLoggedIn($currentStatus, &$response, $body, &$user, &$token_data = null, $reset = false)
    {
        $currentStatus = $this->errorResponseIfMissingOrNotString($currentStatus, $response, $body, 'token');
        if ($currentStatus) {
            return true;
        }

        $token_data = $this->c->tokens->validate($body['token']);
        $error = $this->getUserFromTokenData(false, $response, $token_data, $user, $reset);
        return $error;
    }

    public function getUserFromTokenData($currentStatus, &$response, $token_data, &$user, $reset = false)
    {
        if ($currentStatus) {
            return true;
        }
        if (!$token_data) {
            $response = $response->withJson([
                'error' => 'Invalid token'
            ], 403);
            return true;
        }

        // Insecure
        // if(isset($token_data->user_id)) {
        //   $query = $this->c->queries['user']['get_one'];
        //   $query->bindValue(':id', (int) $token_data->user_id, PDO::PARAM_INT);
        // }
        if (isset($token_data->session) && !$reset) {
            $query = $this->c->queries['user']['get_by_session_token'];
            $query->bindValue(":session_token", base64_decode($token_data->session));
        } elseif (isset($token_data->reset) && $reset) {
            $query = $this->c->queries['user']['get_by_reset_token'];
            $query->bindValue(":reset_token", base64_decode($token_data->reset));
        } else {
            $response = $response->withJson([
                'error' => 'Invalid token'
            ], 403);
            return true;
        }

        $query->execute();

        $currentStatus = $this->errorResponseIfQueryBad(false, $response, $query);
        $currentStatus = $this->errorResponseIfQueryNoResults($currentStatus, $response, $query, 'Nonexistent token submitted');
        if ($currentStatus) {
            return true;
        }

        $user = $query->fetchAll()[0];
        return false;
    }
}
