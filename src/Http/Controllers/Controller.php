<?php

namespace Versatile\Core\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Storage;
use Versatile\Core\Events\FileDeleted;
use Versatile\Core\Components\ContentTypes\Checkbox;
use Versatile\Core\Components\ContentTypes\Coordinates;
use Versatile\Core\Components\ContentTypes\File;
use Versatile\Core\Components\ContentTypes\Image as ContentImage;
use Versatile\Core\Components\ContentTypes\MultipleImage;
use Versatile\Core\Components\ContentTypes\Password;
use Versatile\Core\Components\ContentTypes\Relationship;
use Versatile\Core\Components\ContentTypes\SelectMultiple;
use Versatile\Core\Components\ContentTypes\Text;
use Versatile\Core\Components\ContentTypes\Timestamp;
use Versatile\Core\Http\Controllers\Traits\Actions;
use Versatile\Core\Http\Controllers\Traits\Filters;
use Versatile\Core\Models\DataType;
use Versatile\Core\Traits\AlertsMessages;
use Validator;

abstract class Controller extends BaseController
{
    use DispatchesJobs;
    use ValidatesRequests;
    use AuthorizesRequests;
    use AlertsMessages;
    use Actions;
    use Filters;

    /**
     * @var null|string
     */
    protected $actionsFormat = null;

    /**
     * Controller constructor.
     */
    public function __construct()
    {
        $this->defineActionsFormat();

    }

    /**
     * @param Request $request
     * @return null|string
     */
    public function getDataTypeSlug(Request $request)
    {
        if(!is_null($this->dataTypeSlug)) {
            return $this->dataTypeSlug;
        }

        $slug = explode('.', $request->route()->getName());

        if (isset($slug[1])) {
            return $slug[1];
        }

        return null;
    }

    /**
     * @param $dataTypeSlug
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getDataType($dataTypeSlug)
    {
        // Get the DataType based on the slug
        if (is_null($this->dataType)) {
            return DataType::where('slug', '=', $dataTypeSlug)->first();
        }

        return $this->dataType;
    }

    /**
     * @param DataType $dataType
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function getModel($dataType)
    {
        if (is_null($this->model)) {
            return app($dataType->model_name);
        }

        return app($this->model);
    }

    /**
     * Get the filters available for the resource.
     *
     * @return array
     */
    public function filters()
    {
        return [];
    }

    /**
     * Get the actions available for the resource.
     *
     * @return array
     */
    public function actions()
    {
        return [];
    }

    public function insertUpdateData($request, $slug, $rows, $data)
    {
        $multi_select = [];

        /*
         * Prepare Translations and Transform data
         */
        $translations = [];
        if (is_bread_translatable($data)) {
            $translations = $data->prepareTranslations($request);
        }

        foreach ($rows as $row) {
            $options = json_decode($row->details);

            // if the field for this row is absent from the request, continue
            // checkboxes will be absent when unchecked, thus they are the exception
            if (!$request->hasFile($row->field) && !$request->has($row->field) && $row->type !== 'checkbox') {
                // if the field is a belongsToMany relationship, don't remove it
                // if no content is provided, that means the relationships need to be removed
                if ((isset($options->type) && $options->type !== 'belongsToMany') || $row->field !== 'user_belongsto_role_relationship') {
                    continue;
                }
            }

            if ($row->type == 'relationship' && $options->type != 'belongsToMany') {
                $row->field = @$options->column;
            }

            $content = $this->getContentBasedOnType($request, $slug, $row, $options);

            /*
             * merge ex_images and upload images
             */
            if ($row->type == 'multiple_images' && !is_null($content)) {
                if (isset($data->{$row->field})) {
                    $ex_files = json_decode($data->{$row->field}, true);
                    if (!is_null($ex_files)) {
                        $content = json_encode(array_merge($ex_files, json_decode($content)));
                    }
                }
            }

            if (is_null($content)) {

                // If the image upload is null and it has a current image keep the current image
                if ($row->type == 'image' && is_null($request->input($row->field)) && isset($data->{$row->field})) {
                    $content = $data->{$row->field};
                }

                // If the multiple_images upload is null and it has a current image keep the current image
                if ($row->type == 'multiple_images' && is_null($request->input($row->field)) && isset($data->{$row->field})) {
                    $content = $data->{$row->field};
                }

                // If the file upload is null and it has a current file keep the current file
                if ($row->type == 'file') {
                    $content = $data->{$row->field};
                }

                if ($row->type == 'password') {
                    $content = $data->{$row->field};
                }
            }

            if ($row->type == 'relationship' && $options->type == 'belongsToMany') {
                // Only if select_multiple is working with a relationship
                $multi_select[] = [
                    'model' => $options->model,
                    'content' => $content,
                    'table' => $options->pivot_table
                ];

            } else {
                $data->{$row->field} = $content;
            }
        }

        $data->save();

        // Save translations
        if (count($translations) > 0) {
            $data->saveTranslations($translations);
        }

        foreach ($multi_select as $sync_data) {
            $data->belongsToMany($sync_data['model'], $sync_data['table'])->sync($sync_data['content']);
        }

        return $data;
    }

    /**
     * Validates bread POST request.
     *
     * @param \Illuminate\Http\Request $request
     * @param array $data
     * @param string|null $name
     * @param int|null $id
     * @return \Illuminate\Validation\Validator
     */
    public function validateBread($request, $data, $name = null, $id = null)
    {
        $rules = [];
        $messages = [];
        $customAttributes = [];
        $is_update = $name && $id;

        $fieldsWithValidationRules = $this->getFieldsWithValidationRules($data);

        foreach ($fieldsWithValidationRules as $field) {
            $options = json_decode($field->details);
            $fieldRules = $options->validation->rule;
            $fieldName = $field->field;

            // Show the field's display name on the error message
            if (!empty($field->display_name)) {
                $customAttributes[$fieldName] = $field->display_name;
            }

            // Get the rules for the current field whatever the format it is in
            $rules[$fieldName] = is_array($fieldRules) ? $fieldRules : explode('|', $fieldRules);

            // Fix Unique validation rule on Edit Mode
            if ($is_update) {
                foreach ($rules[$fieldName] as &$fieldRule) {
                    if (strpos(strtoupper($fieldRule), 'UNIQUE') !== false) {
                        $fieldRule = \Illuminate\Validation\Rule::unique($name)->ignore($id);
                    }
                }
            }

            // Set custom validation messages if any
            if (!empty($options->validation->messages)) {
                foreach ($options->validation->messages as $key => $msg) {
                    $messages["{$fieldName}.{$key}"] = $msg;
                }
            }
        }

        return Validator::make($request, $rules, $messages, $customAttributes);
    }

    /**
     * @param Request $request
     * @param $slug
     * @param $row
     * @param null $options
     * @return \Illuminate\Database\Query\Expression|int|mixed|null|string|void|static
     */
    public function getContentBasedOnType(Request $request, $slug, $row, $options = null)
    {
        switch ($row->type) {

            // PASSWORD TYPE
            case 'password':
                return (new Password($request, $slug, $row, $options))->handle();

            // CHECKBOX TYPE
            case 'checkbox':
                return (new Checkbox($request, $slug, $row, $options))->handle();

            // FILE TYPE
            case 'file':
                return (new File($request, $slug, $row, $options))->handle();

            // MULTIPLE IMAGES TYPE
            case 'multiple_images':
                return (new MultipleImage($request, $slug, $row, $options))->handle();

            // SELECT MULTIPLE TYPE
            case 'select_multiple':
                return (new SelectMultiple($request, $slug, $row, $options))->handle();

            // IMAGE TYPE
            case 'image':
                return (new ContentImage($request, $slug, $row, $options))->handle();

            // TIMESTAMP TYPE
            case 'timestamp':
                return (new Timestamp($request, $slug, $row, $options))->handle();

            // COORDINATES TYPE
            case 'coordinates':
                return (new Coordinates($request, $slug, $row, $options))->handle();

            // RELATIONSHIPS TYPE
            case 'relationship':
                return (new Relationship($request, $slug, $row, $options))->handle();

            // ALL OTHER TEXT TYPE
            default:
                return (new Text($request, $slug, $row, $options))->handle();
        }
    }

    /**
     * Delete file if exists
     *
     * @param $path
     */
    public function deleteFileIfExists($path)
    {
        if (Storage::disk(config('versatile.storage.disk'))->exists($path)) {
            Storage::disk(config('versatile.storage.disk'))->delete($path);
            event(new FileDeleted($path));
        }
    }

    /**
     * Get fields having validation rules in proper format.
     *
     * @param array $fieldsConfig
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getFieldsWithValidationRules($fieldsConfig)
    {
        return $fieldsConfig->filter(function ($value) {
            if (empty($value->details)) {
                return false;
            }

            $decoded = json_decode($value->details, true);

            return !empty($decoded['validation']['rule']);
        });
    }
}
