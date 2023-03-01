<?php

namespace Rakutentech\LaravelRequestDocs;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

class LaravelRequestDocs
{
    /**
     * @param  bool  $showGet
     * @param  bool  $showPost
     * @param  bool  $showPut
     * @param  bool  $showPatch
     * @param  bool  $showDelete
     * @param  bool  $showHead
     * @return \Rakutentech\LaravelRequestDocs\Doc[]
     */
    public function getDocs(
        bool $showGet,
        bool $showPost,
        bool $showPut,
        bool $showPatch,
        bool $showDelete,
        bool $showHead
    ): array {
        $filteredMethods = array_filter([
            Request::METHOD_GET    => $showGet,
            Request::METHOD_POST   => $showPost,
            Request::METHOD_PUT    => $showPut,
            Request::METHOD_PATCH  => $showPatch,
            Request::METHOD_DELETE => $showDelete,
            Request::METHOD_HEAD   => $showHead,
        ], function (string $method) {
            return $method;
        });

        /** @var string[] $methods */
        $methods = array_keys($filteredMethods);

        $docs = $this->getControllersInfo($methods);
        $docs = $this->appendRequestRules($docs);

        return array_filter($docs);
    }

    /**
     * Split {@see \Rakutentech\LaravelRequestDocs\Doc} by {@see \Rakutentech\LaravelRequestDocs\Doc::$methods}
     *
     * @param  \Rakutentech\LaravelRequestDocs\Doc[]  $docs
     * @return \Rakutentech\LaravelRequestDocs\Doc[]
     */
    public function splitByMethods(array $docs): array
    {
        /** @var \Rakutentech\LaravelRequestDocs\Doc[] $splitDocs */
        $splitDocs = [];

        foreach ($docs as $doc) {
            foreach ($doc->getMethods() as $method) {
                $cloned = $doc->clone();
                $cloned->setMethods([$method]);
                $cloned->setHttpMethod($method);
                $splitDocs[] = $cloned;
            }
        }

        return $splitDocs;
    }

    /**
     * @param  \Rakutentech\LaravelRequestDocs\Doc[]  $docs
     * @param  string|null  $sortBy
     * @return \Rakutentech\LaravelRequestDocs\Doc[]
     */
    public function sortDocs(array $docs, ?string $sortBy = 'default'): array
    {
        if (!in_array($sortBy, ['route_names', 'method_names'])) {
            return $docs;
        }

        if ($sortBy === 'route_names') {
            sort($docs);
            return $docs;
        }

        // Sort by `method_names`.
        $methods = [
            Request::METHOD_GET,
            Request::METHOD_POST,
            Request::METHOD_PUT,
            Request::METHOD_PATCH,
            Request::METHOD_DELETE,
            Request::METHOD_HEAD,
        ];

        $sorted = collect($docs)->sortBy(function (Doc $doc) use ($methods) {
            return array_search($doc->getMethods()[0], $methods);
        }, SORT_NUMERIC);

        return $sorted->values()->all();
    }

    /**
     * @param  \Rakutentech\LaravelRequestDocs\Doc[]  $docs
     * @return \Rakutentech\LaravelRequestDocs\Doc[]
     */
    public function groupDocs(array $docs, ?string $groupBy = 'default'): array
    {
        if (!in_array($groupBy, ['api_uri', 'controller_full_path'])) {
            return $docs;
        }

        if ($groupBy === 'api_uri') {
            $this->groupDocsByAPIURI($docs);
        }

        if ($groupBy === 'controller_full_path') {
            $this->groupDocsByFQController($docs);
        }

        return collect($docs)
            ->sortBy(function (Doc $doc) {
                return $doc->getGroup() . $doc->getGroupIndex();
            }, SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * @param  string[]  $onlyMethods
     * @return \Rakutentech\LaravelRequestDocs\Doc[]
     * @throws \ReflectionException
     */
    public function getControllersInfo(array $onlyMethods): array
    {
        /** @var \Rakutentech\LaravelRequestDocs\Doc[] $docs */
        $docs   = [];
        $routes = Route::getRoutes()->getRoutes();

        $onlyRouteStartWith = config('request-docs.only_route_uri_start_with') ?? '';
        $excludePatterns    = config('request-docs.hide_matching') ?? [];

        foreach ($routes as $route) {
            if ($onlyRouteStartWith && !Str::startsWith($route->uri, $onlyRouteStartWith)) {
                continue;
            }

            foreach ($excludePatterns as $regex) {
                if (preg_match($regex, $route->uri)) {
                    continue 2;
                }
            }

            $routeMethods = array_intersect($route->methods, $onlyMethods);

            if (empty($routeMethods)) {
                continue;
            }

            $controllerName     = '';
            $controllerFullPath = '';
            $method             = '';

            // `$route->action['uses']` value is either 'Class@method' string or Closure.
            if (is_string($route->action['uses'])) {
                $controllerCallback = Str::parseCallback($route->action['uses']);
                $controllerFullPath = $controllerCallback[0];
                $method             = $controllerCallback[1];
                $controllerName     = (new ReflectionClass($controllerFullPath))->getShortName();
            }

            $doc = new Doc(
                $route->uri,
                $routeMethods,
                config('request-docs.hide_meta_data') ? [] : $route->middleware(),
                config('request-docs.hide_meta_data') ? '' : $controllerName,
                config('request-docs.hide_meta_data') ? '' : $controllerFullPath,
                config('request-docs.hide_meta_data') ? '' : $method,
                '',
                [],
                '',
            );

            $docs[] = $doc;
        }

        return $docs;
    }

    /**
     * @param  \Rakutentech\LaravelRequestDocs\Doc[]  $controllersInfo
     * @return \Rakutentech\LaravelRequestDocs\Doc[]
     * @throws \ReflectionException
     */
    public function appendRequestRules(array $controllersInfo): array
    {
        foreach ($controllersInfo as $controllerInfo) {
            if ($controllerInfo->isClosure()) {
                // Skip to next if controller is not exists.
                continue;
            }

            $reflectionMethod = new ReflectionMethod($controllerInfo->getControllerFullPath(), $controllerInfo->getMethod());

            $docComment = $this->getDocComment($reflectionMethod);

            $customRules = $this->customParamsDocComment($docComment);
            $controllerInfo->setResponses($this->customResponsesDocComment($docComment));

            foreach ($reflectionMethod->getParameters() as $param) {
                /** @var \ReflectionNamedType|\ReflectionUnionType|\ReflectionIntersectionType|null $namedType */
                $namedType = $param->getType();
                if (!$namedType) {
                    continue;
                }

                try {
                    $requestClassName = $namedType->getName();
                    $reflectionClass  = new ReflectionClass($requestClassName);
                    $requestObject    = $reflectionClass->newInstanceWithoutConstructor();

                    foreach (config('request-docs.rules_methods') as $requestMethod) {
                        if (!method_exists($requestObject, $requestMethod)) {
                            continue;
                        }

                        try {
                            $controllerInfo->mergeRules($this->flattenRules($requestObject->$requestMethod()));
                        } catch (Throwable $e) {
                            $controllerInfo->mergeRules($this->rulesByRegex($requestClassName, $requestMethod));
                        }
                    }
                } catch (Throwable $th) {
                    // Do nothing.
                }

                $controllerInfo->mergeRules($customRules);
            }

            $controllerInfo->setDocBlock($this->lrdDocComment($docComment));
        }
        return $controllersInfo;
    }

    public function lrdDocComment(string $docComment): string
    {
        $lrdComment = "";
        $counter    = 0;
        foreach (explode("\n", $docComment) as $comment) {
            $comment = trim($comment);
            // check contains in string
            if (Str::contains($comment, '@lrd')) {
                $counter++;
            }
            if ($counter == 1 && !Str::contains($comment, '@lrd')) {
                if (Str::startsWith($comment, '*')) {
                    $comment = trim(substr($comment, 1));
                }
                // remove first character from string
                $lrdComment .= $comment . "\n";
            }
        }
        return $lrdComment;
    }

    /**
     * @param  array<string, \Illuminate\Contracts\Validation\Rule|array|string>  $mixedRules
     * @return array<string, string[]>
     */
    public function flattenRules(array $mixedRules): array
    {
        /** @var array<string, string[]> $rules */
        $rules = [];

        foreach ($mixedRules as $attribute => $rule) {
            if (is_object($rule)) {
                $rules[$attribute][] = get_class($rule);
                continue;
            }

            if (is_array($rule)) {
                /** @var string[] $rulesStrs */
                $rulesStrs = [];

                foreach ($rule as $ruleItem) {
                    $rulesStrs[] = is_object($ruleItem) ? get_class($ruleItem) : $ruleItem;
                }

                $rules[$attribute][] = implode("|", $rulesStrs);
                continue;
            }

            $rules[$attribute][] = $rule;
        }

        return $rules;
    }

    /**
     * @return array<string, string[]>
     * @throws \ReflectionException
     */
    public function rulesByRegex(string $requestClassName, string $methodName): array
    {
        $data  = new ReflectionMethod($requestClassName, $methodName);
        $lines = file($data->getFileName());
        $rules = [];

        for ($i = $data->getStartLine() - 1; $i <= $data->getEndLine() - 1; $i++) {
            // check if line is a comment
            $trimmed = trim($lines[$i]);
            if (Str::startsWith($trimmed, '//') || Str::startsWith($trimmed, '#')) {
                continue; // @codeCoverageIgnore
            }
            // check if => in string, only pick up rules that are coded on single line
            if (Str::contains($lines[$i], '=>')) {
                preg_match_all("/(?:'|\").*?(?:'|\")/", $lines[$i], $matches);
                $rules[] = $matches;
            }
        }

        $rules = collect($rules)
            ->filter(function ($item) {
                return count($item[0]) > 0;
            })
            ->transform(function ($item) {
                $fieldName         = Str::of($item[0][0])->replace(['"', "'"], '');
                $definedFieldRules = collect(array_slice($item[0], 1))->transform(function ($rule) {
                    return Str::of($rule)->replace(['"', "'"], '')->__toString();
                })->toArray();

                return ['key' => $fieldName, 'rules' => $definedFieldRules];
            })
            ->keyBy('key')
            ->transform(function ($item) {
                return $item['rules'];
            })->toArray();

        return $rules;
    }

    /**
     * @param  string  $docComment
     * @return array<string, string[]>
     */
    private function customParamsDocComment(string $docComment): array
    {
        $params = [];

        foreach (explode("\n", $docComment) as $comment) {
            if (!Str::contains($comment, '@LRDparam')) {
                continue;
            }

            $comment = trim(Str::replace(['@LRDparam', '*'], '', $comment));

            $comments = $this->multiExplode([' ', '|'], $comment);

            if (count($comments) > 0) {
                $params[$comments[0]] = array_values(array_filter($comments, fn($item) => $item !== $comments[0]));
            }
        }

        return $params;
    }

    /**
     * @param  string  $docComment
     * @return string[]
     */
    private function customResponsesDocComment(string $docComment): array
    {
        /** @var string[] $params */
        $params = [];

        foreach (explode("\n", $docComment) as $comment) {
            if (!Str::contains($comment, '@LRDresponses')) {
                continue;
            }

            $comment = trim(Str::replace(['@LRDresponses', '*'], '', $comment));

            $params = $this->multiExplode([' ', '|'], $comment);
        }

        if (count($params) === 0) {
            return config('request-docs.default_responses') ?? [];
        }

        return $params;
    }

    /**
     * @param  string[]  $delimiters
     * @return string[]
     */
    private function multiExplode(array $delimiters, string $string): array
    {
        $ready = str_replace($delimiters, $delimiters[0], $string);
        return explode($delimiters[0], $ready);
    }

    /**
     * Parse the `$docs['uri']` and attach `group` and `group_index` details.
     *
     * @param  \Rakutentech\LaravelRequestDocs\Doc[]  $docs
     */
    private function groupDocsByAPIURI(array $docs): void
    {
        $patterns = config('request-docs.group_by.uri_patterns', []);

        $regex = count($patterns) > 0 ? '(' . implode('|', $patterns) . ')' : '';

        // A collection<string, int> to remember indexes with `group` => `index` pair.
        $groupIndexes = collect();

        foreach ($docs as $doc) {
            if ($regex !== '') {
                // If $regex    = '^api/v[\d]+/',
                // and $uri     = '/api/v1/users',
                // then $prefix = '/api/v1/'.
                $prefix = Str::match($regex, $doc->getUri());
            }

            $group = $this->getGroupByURI($prefix ?? '', $doc->getUri());
            $this->rememberGroupIndex($groupIndexes, $group);
            $this->setGroupInfo($doc, $group, $groupIndexes->get($group));
        }
    }

    /**
     * Create and return group name by the `$uri`.
     */
    private function getGroupByURI(string $prefix, string $uri): string
    {
        if ($prefix === '') {
            // No prefix, create group by the first path.
            $paths = explode('/', $uri);
            return $paths[0];
        }

        // Glue the prefix + "first path after prefix" to form a group.
        $after = (Str::after($uri, $prefix));
        $paths = explode('/', $after);
        return $prefix . $paths[0];
    }

    /**
     * Parse the `$docs['controller_full_path']` and attach `group` and `group_index` details.
     *
     * @param  \Rakutentech\LaravelRequestDocs\Doc[]  $docs
     */
    private function groupDocsByFQController(array $docs): void
    {
        // To remember group indexes with group => index pair.
        $groupIndexes = collect();

        foreach ($docs as $doc) {
            $group = $doc->getControllerFullPath();
            $this->rememberGroupIndex($groupIndexes, $group);
            $this->setGroupInfo($doc, $group, $groupIndexes->get($group));
        }
    }

    /**
     * Set the last index number into `$groupIndexes`
     *
     * @param  \Illuminate\Support\Collection<string, int>  $groupIndexes  [`group` => `index`]
     */
    private function rememberGroupIndex(Collection $groupIndexes, string $key): void
    {
        if (!$groupIndexes->has($key)) {
            $groupIndexes->put($key, 0);
            return;
        }

        $groupIndexes->put($key, $groupIndexes->get($key) + 1);
    }

    /**
     * Attach `group` and `group_index` into `$doc`.
     */
    private function setGroupInfo(Doc $doc, string $group, int $groupIndex): void
    {
        $doc->setGroup($group);
        $doc->setGroupIndex($groupIndex);
    }

    /**
     * @param  \ReflectionMethod  $reflectionMethod
     * @return string
     */
    private function getDocComment(ReflectionMethod $reflectionMethod): string
    {
        $docComment = $reflectionMethod->getDocComment();

        if ($docComment === false) {
            return '';
        }

        return $docComment;
    }
}
