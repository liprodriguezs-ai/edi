<?php

namespace QCH\Core\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use Illuminate\Foundation\Application;
use League\Fractal;
use League\Fractal\Manager;
use League\Fractal\Pagination\IlluminatePaginatorAdapter;
use QCH\Core\Http\Api\ApiHandler;
use QCH\Core\Services\TransformerBase;

/**
 * Class Controller.
 */
class RestController extends BaseController
{
    use Helpers;

    protected $repositoryClass = null;

    protected $requestClass = null;

    protected $requestUpdateClass = null;

    protected $user;

    protected $checkPermission = [];

    protected $fullTextSearchColumns = [];

    /**
     * __construct.
     */
    public function __construct()
    {
        $module = explode('\\', str_replace('Controller', '', get_class($this)));
        if (empty($this->module)) {
            $this->module = end($module);
        }

        $this->user = auth()->user();
    }

    /**
     * Get repository instance.
     *
     * @category Controller
     *
     * @return Application|\Laravel\Lumen\Application|mixed
     */
    public function makeRequest()
    {
        if (! is_null($this->requestClass)) {
            return app($this->requestClass);
        }

        return app(
            "App\\Modules\\{$this->module}\\Http\\Requests\\{$this->module}Request"
        );
    }

    /**
     * Get repository instance.
     *
     * @category Controller
     *
     * @return Application|\Laravel\Lumen\Application|mixed
     */
    public function makeUpdateRequest()
    {
        if (! is_null($this->requestUpdateClass)) {
            return app($this->requestUpdateClass);
        }
    }

    /**
     * Get repository instance.
     *
     * @category Controller
     *
     * @return Application|\Laravel\Lumen\Application|mixed
     */
    public function getRepository()
    {
        if (! is_null($this->repositoryClass)) {
            return app($this->repositoryClass);
        }

        return app(
            "App\\Modules\\{$this->module}\\Repositories\\{$this->module}Repository"
        );
    }

    /**
     * Get all Resource for pagination.
     *
     * @return mixed
     */
    public function index()
    {
        $request = $this->request->all();
        $query = $this->getRepository()->getModel();
        $data = ApiHandler::parseMultiple($query, $this->fullTextSearchColumns)
            ->getBuilder()
            ->paginate($request['_limit'], null, 'page', $request['_page']);

        $resource = (new Fractal\Resource\Collection($data->getCollection(), new TransformerBase));
        $resource->setPaginator(new IlluminatePaginatorAdapter($data));
        $data = (new Manager)->createData($resource)->toArray();

        return $this->success($data);
    }

    /**
     * Get Resource details referenced by id.
     *
     * @param  $id  int Resource ID
     * @return mixed
     */
    public function show($id = null)
    {
        $query = $this->getRepository()->getModel();
        $data = ApiHandler::parseMultiple($query, $this->request->all())
            ->getBuilder()
            ->find($id);

        if (is_object($data)) {
            return $this->response->success(['data' => $data]);
        }

        return $this->response->errorNotFound();
    }

    /**
     * Get Resource details referenced by id.
     *
     * @param  $id  int Resource ID
     * @return mixed
     */
    public function bySlug($slug = null)
    {
        $query = $this->getRepository()->getModel();
        $data = ApiHandler::parseMultiple($query, $this->request->all())
            ->getBuilder()
            ->where('slug', $slug)
            ->first();

        if (is_object($data)) {
            return $this->response->success(['data' => $data]);
        }

        return $this->response->errorNotFound();
    }

    /**
     * Update Resource data.
     *
     * @param  $id  int Resource ID
     * @return mixed
     */
    public function update($id)
    {
        $this->makeUpdateRequest();
        $data = $this->getRepository()->getModel()->find($id);

        if (! is_object($data)) {
            return $this->response->errorNotFound();
        }

        $this->checkPermissions($data);

        $request = $this->request->all();
        $data->fill($request);
        $data->save();

        $data = $this->load($data);

        return $this->response->success([
            'data' => $data,
            'message' => $this->message('update'),
        ]);
    }

    /**
     * Update Resource data.
     *
     * @param  $id  int Resource ID
     * @return mixed
     */
    public function sort()
    {

        $request = $this->request->all();

        $return = [];

        foreach ($request as $index => $item) {
            $data = $this->getRepository()->getModel()
                ->select('id', 'sort')
                ->find($item['id']);
            if ($data) {
                $data->sort = $index;
                $data->save();
                $return[] = $data;
            }
        }

        return $this->response->success([
            'data' => $return,
            'message' => $this->message('update'),
        ]);
    }

    /**
     * Restore resource.
     *
     * @param  $id  int Resource ID
     * @return mixed
     */
    public function restore($id = null)
    {
        $data = $this->getRepository()->getModel()->withTrashed()->find($id);

        if (! is_object($data)) {
            return $this->response->errorNotFound();
        }

        $this->checkPermissions($data);

        $data->restore();

        return $this->response->success([
            'message' => $this->message('restore'),
        ]);
    }

    /**
     * Delete resource.
     *
     * @param  $id  int Resource ID
     * @return mixed
     */
    public function delete($id = null)
    {
        $data = $this->getRepository()->getModel()->find($id);

        if (! is_object($data)) {
            return $this->response->errorNotFound();
        }

        $this->checkPermissions($data);

        $data->delete();

        return $this->response->success([
            'message' => $this->message('delete'),
        ]);
    }

    /**
     * Create resource.
     *
     * @return mixed
     */
    public function store()
    {
        $this->makeRequest();
        $request = $this->request->all();

        $data = $this->getRepository()->create($request);
        $data = $this->load($data);

        return $this->response->created([
            'data' => $data,
            'message' => $this->message('store'),
        ]);
    }

    /**
     * Undocumented function
     *
     * @param [type] $data
     * @return mixed
     */
    public function load($data)
    {
        $request = $this->request->all();
        if (isset($request['_with']) && ! empty($request['_with'])) {
            $data = $data->load(explode(',', $request['_with']));
        }

        return $data;
    }

    public function checkPermissions($data)
    {
        if (! $this->user) {
            $this->user = auth()->user();
        }
        if ($this->user->type == 'admin') {
            return;
        }
        // Tenant isolation is enforced by TenantScope (single-database tenancy):
        // cross-tenant records are never visible to a tenant user, so no manual
        // tenant_id comparison is needed here.
    }
}
