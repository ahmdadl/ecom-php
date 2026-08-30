<?php

namespace HZ\Illuminate\Mongez\Http;

use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

/**
 * @method \Symfony\Component\HttpFoundation\Response success(?array<string, mixed> $data = null)
 * @method \Symfony\Component\HttpFoundation\Response badRequest($data)
 * @method \Symfony\Component\HttpFoundation\Response notFound($data = null)
 */
abstract class AdminUIController
{
    /**
     * Repository object
     * 
     * @var mixed
     */
    protected $repository;

    /**
     * Controller repository
     *
     * @var mixed
     */
    protected $controllerInfo = [
        'repository' => '',
        'view' => '',
        'listOptions' => [
            'select' => [],
            'paginate' => null
        ],
        'returnOn' => [
            'store' => 'single-record',
            'update' => 'single-record',
        ],
        'rules' => [
            'all' => [],
            'store' => [],
            'update' => [],
        ],
    ];

    /**
     * Constructor
     *
     */
    public function __construct()
    {
        if (!empty($this->controllerInfo['repository'])) {
            $this->repository = repo($this->controllerInfo['repository']);
        }
    }

    /**
     * Get List of records
     *
     * @return \Illuminate\Contracts\View\View
     */
    public function index(Request $request)
    {
        $data['records'] = $this->repository->list($this->listOptions($request));

        if ($pagination = $this->repository->getPaginationInfo()) {
            $json['paginationInfo'] = $pagination;
        }

        return $this->render('index', $data);
    }

    /**
     * Render the given view path
     *
     * @param array<string, mixed> $data
     * @return \Illuminate\Contracts\View\View
     */
    protected function render(string $viewPath, array $data = [])
    {
        return view($this->controllerInfo['view-path'] . '.' . $viewPath, $data);
    }

    /**
     * Get  options
     *
     * @return array<string, mixed>
     */
    protected function listOptions(Request $request): array
    {
        return array_merge($request->All(), $this->controllerInfo('listOptions'), ['as-model' => true]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Contracts\View\View|\Symfony\Component\HttpFoundation\Response
     */
    public function show($id)
    {
        $id = (int) $id;

        if (!$this->repository->has($id)) {
            return $this->notFound();
        }

        $data['record'] = $this->repository->getModel($id);

        return $this->render('index', $data);
    }

    /**
     * Get value from controller info
     *
     * @return mixed
     */
    protected function controllerInfo(string $key)
    {
        return Arr::get($this->controllerInfo, $key);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function store(Request $request)
    {
        $rules = array_merge((array) $this->controllerInfo('rules.all'), $this->storeValidation($request));

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->badRequest($validator->errors());
        }

        $model = $this->repository->create($request);

        $returnOnStore = $this->controllerInfo['returnOn']['store'] ?? config('mongez.admin.returnOn.store', 'single-record');
        if ($returnOnStore == 'single-record') {
            return $this->show($model->nid);
        }

        if ($returnOnStore == 'all-records') {
            return $this->index($request);
        }
        return redirect()->back();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int $id
     * @return \Symfony\Component\HttpFoundation\Response|\Illuminate\Http\RedirectResponse
     */
    public function destroy($id, Request $request)
    {
        if ($this->repository->deleteHasDependence()) {
            $deletingValidationErrors = $this->validateBeforeDeleting($this->repository->getDeleteDependencies(), $id);
            if ($deletingValidationErrors !== []) {
                return $this->badRequest($deletingValidationErrors);
            }
        }

        $this->repository->delete((int) $id);

        return $request->isAjax() ? $this->success() : redirect()->back(); // @phpstan-ignore method.notFound
    }

    /**
     * Validate if this model has depended on another these tables .
     *
     * @param  array<mixed> $deleteDependenceTables
     * @param  int   $modelId
     * @return array<int, mixed> $errors
     */
    public function validateBeforeDeleting($deleteDependenceTables, $modelId): array
    {
        $errors = [];

        /** @var mixed $validationRules */
        $validationRules = [];

        $isUsingSoftDelete = $this->repository->isUsingSoftDelete();

        foreach ($deleteDependenceTables as $table) {
            $rules = Rule::unique($table['tableName'], $table['key']);

            if ($isUsingSoftDelete) {
                $validationRules = $rules->where(function ($query) {

                    $query->whereNull('deleted_at');
                });
            }

            $validationRules['data'][$table['tableName'] . '_id'] = (int)$modelId;

            $validationRules['rules'][$table['tableName'] . '_id'] = $rules;

            $validationRules['messages']['unique.' . $table['tableName'] . '_id'] = $table['message'];
        }

        $validator = Validator::make((array) $validationRules['data'], (array) $validationRules['rules'], (array) $validationRules['messages']);

        if ($validator->fails()) {
            $errors[] = $validator->errors();
        }

        return $errors;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int $id
     * @return \Illuminate\Contracts\View\View|\Symfony\Component\HttpFoundation\Response
     */
    public function update(Request $request, $id)
    {
        if (!$this->repository->has($id)) {
            return $this->notFound();
        }

        $rules = array_merge((array) $this->controllerInfo('rules.all'), $this->updateValidation($id, $request));

        foreach ($rules as &$rulesList) {
            if (!is_array($rulesList)) {
                $rulesList = explode('|', $rulesList);
            }

            foreach ($rulesList as &$rule) {
                if ($rule == 'unique') {
                    if (!Str::contains($rule, ':')) {
                        $rule = Rule::unique($this->repository->getTableName())->ignore((int) $id, 'nid');
                    }
                }
            }
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->badRequest($validator->errors());
        }

        $this->repository->update($id, $request);

        $returnOnUpdate = $this->controllerInfo['returnOn']['update'] ?? config('mongez.admin.returnOn.update', 'single-record');
        if ($returnOnUpdate == 'single-record') {
            return $this->show($id);
        }

        if ($returnOnUpdate == 'all-records') {
            return $this->index($request);
        }
        return $this->success();
    }

    /**
     * Make custom validation for update
     *
     * @param  int $id
     * @return array<string, mixed>
     */
    protected function updateValidation($id, Request $request): array
    {
        return (array) $this->controllerInfo('rules.update');
    }

    /**
     * Make custom validation for store
     *
     * @param mixed $request
     * @return array<string, mixed>
     */
    protected function storeValidation($request): array
    {
        return (array) $this->controllerInfo('rules.store');
    }
}
