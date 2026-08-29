<?php

namespace HZ\Illuminate\Mongez\Repository\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use HZ\Illuminate\Mongez\Services\Images\ImageResize;

/**
 * @phpstan-require-extends \HZ\Illuminate\Mongez\Repository\RepositoryManager
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
trait Fillers
{
    /**
     * Storage directory path
     */
    protected string $storageDirectory;

    /**
     * Force ignore any column that was not sent in request
     */
    public bool $forceIgnore = false;

    /**
     * Get request object with data
     *
     * @param  \Illuminate\Http\Request|array<string, mixed> $data
     */
    protected function getRequestWithData($data): Request
    {
        // keep original request untouched, just clone it
        if (is_array($data)) {
            $request = new Request();
            $request->merge($data);
            // Merge files
            foreach ($data as $key => $file) {
                if ($file instanceof UploadedFile) {
                    $request->files->set($key, $file);
                }
            }
        } else {
            $request = clone $data;
        }

        $this->request = $request;

        return $request;
    }

    /**
     * Set data automatically from the DATA array
     *
     * @param  TModel $model
     * @return void
     */
    protected function setAutoData($model)
    {
        $this->setMainData($model);

        $this->setLocalizedData($model);

        $this->setArraybleData($model);

        $this->upload($model, static::UPLOADS);

        $this->setStringData($model);

        $this->setIntData($model);

        $this->setFloatData($model);

        $this->setDateData($model);

        $this->setBoolData($model);
    }

    /**
     * Check if all request inputs are in patchable array
     *
     * @param  \Illuminate\Http\Request $request
     */
    protected function checkPatchable($request): bool
    {
        foreach ($request->all() as $column => $input) {
            if (!in_array($column, static::PATCHABLE_DATA)) return false;
        }

        return true;
    }

    /**
     * Set string data automatically from the DATA array
     *
     * @param  TModel $model
     * @return void
     */
    protected function setStringData($model)
    {
        foreach (static::STRING_DATA as $input => $column) {
            if (is_numeric($input)) {
                $input = $column;
            }

            if ($this->isIgnorable($input)) continue;

            if ($input === 'password') {
                if ($password = $this->input('password')) {
                    $model->setAttribute('password', bcrypt($password));
                }

                continue;
            }

            $this->setToModel($model, $column, (string) $this->input($input));
        }
    }

    /**
     * Set main data automatically from the DATA array
     *
     * @param  TModel $model
     * @return void
     */
    protected function setMainData($model)
    {
        foreach (static::DATA as $input => $column) {
            if (is_numeric($input)) {
                $input = $column;
            }

            if ($this->isIgnorable($input)) continue;

            if ($column === 'password') {
                if ($password = $this->input('password')) {
                    $model->setAttribute('password', bcrypt($password));
                }

                continue;
            }

            $this->setToModel($model, $column, $this->input($input));
        }
    }

    /**
     * Set localized data automatically from the LOCALIZED_DATA array
     *
     * @param  TModel $model
     * @return void
     */
    protected function setLocalizedData($model)
    {
        foreach (static::LOCALIZED_DATA as $input => $column) {
            if (is_numeric($input)) {
                $input = $column;
            }

            if ($this->isIgnorable($input)) continue;

            $this->setToModel($model, $column, $this->input($input));
        }
    }

    /**
     * Set Arrayble data automatically from the DATA array
     *
     * @param  TModel $model
     * @return void
     */
    protected function setArraybleData($model)
    {
        foreach (static::ARRAYBLE_DATA as $input => $column) {
            if (is_numeric($input)) {
                $input = $column;
            }

            if ($this->isIgnorable($input)) continue;

            $value = array_filter((array) $this->input($input));

            $value = $this->handleArrayableValue($value);

            $this->setToModel($model, $column, $value);
        }
    }

    /**
     * Set uploads data automatically from the DATA array
     *
     * @param  TModel $model
     * @param  array<int|string, mixed>|null $columns
     * @return void
     */
    protected function upload($model, $columns = null)
    {
        if (!$columns) {
            $columns = static::UPLOADS;
        }

        $this->storageDirectory = $this->getUploadsStorageDirectoryName() . '/' . $model->getKey();

        foreach ((array) $columns as $name => $column) {
            $options = [
                'clearable' => false,
                'arrayable' => null, // auto check
            ];

            if (is_array($column)) {
                $options = $column;
            } elseif (is_numeric($name)) {
                $options['column'] = $column;
                $options['input'] = $column;
            } else {
                $options['input'] = $name;
                $options['column'] = $column;
            }

            $column = $options['column'] ?? $options['input'];
            $input = $options['input'] ?? $options['column'];
            $clearable = $options['clearable'] ?? false;
            $arrayable = $options['arrayable'] ?? null;

            $file = $this->request->{$input};

            $arrayable ??= is_array($file);

            if (!$file) {
                if ($clearable) {
                    $storedValue = $this->input($input . 'String', $arrayable ? [] : '');

                    $this->setToModel($model, $column, $storedValue);
                } else {
                    $files = $this->mergeOldAndNewFiles([], $column, $model);
                    $this->setToModel($model, $column, $files);
                }

                continue;
            }

            if ($arrayable) {
                $files = [];

                foreach ($file as $index => $fileObject) {
                    if (!$fileObject instanceof UploadedFile || !$fileObject->isValid()) continue;

                    $files[$index] = $this->uploadFile($fileObject);
                }

                $files = $this->mergeOldAndNewFiles($files, $column, $model);

                // based on inherited manager, multiple uploads are stored differently
                // if set to true, then encode the listed files

                if (static::SERIALIZE_MULTIPLE_UPLOADS === true) {
                    $files = json_encode($files);
                }

                $this->setToModel($model, $column, $files);
            } else {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $filePath = $this->uploadFile($file);
                    $this->setToModel($model, $column, $filePath);
                }
            }
        }
    }

    /**
     * Upload the given file and return the new path
     *
     * @param  UploadedFile $file
     */
    public function uploadFile($file): string
    {
        $path = $file->storeAs($this->storageDirectory ?: $this->getUploadsStorageDirectoryName(), $this->getFileName($file));

        if ($path === false) {
            throw new \RuntimeException('Unable to store the uploaded file');
        }

        return $path;
    }

    /**
     * Get file name
     */
    protected function getFileName(UploadedFile $fileObject): string
    {
        $keepFileName = defined('static::UPLOADS_KEEP_FILE_NAME') ? static::UPLOADS_KEEP_FILE_NAME : config('mongez.repository.uploads.keepUploadsName', true);

        $originalName = $fileObject->getClientOriginalName();

        $extension = File::extension($originalName) ?: (string) $fileObject->guessExtension();

        return false === $keepFileName ? Str::random(40) . '.' . $extension : $this->adjustFileName($originalName);
    }

    /**
     * Adjust the given file name
     */
    private function adjustFileName(string $fileName): string
    {
        $fileName = preg_replace('/(\-+)/', '-', str_replace([
            '-', '(', ')', '%', '#', ' ',
        ], '-', $fileName)) ?? $fileName;

        return preg_replace('/\-\./', '.', $fileName) ?? $fileName;
    }

    /**
     * Merge the given files with the old uploaded ones
     *
     * @param  array<int|string, mixed> $files
     * @param  string $column
     * @param  TModel $model
     * @return array<int|string, mixed>
     */
    private function mergeOldAndNewFiles(array $files, $column, $model)
    {
        $filesFromRequest = array_map(fn($file) => ltrim($file, '/'), (array) $this->input($column . 'String', []));

        $images = Arr::get($model, $column);

        if ($images && is_string($images) || ($filesFromRequest === [] && $files === [])) return $images;

        foreach ($filesFromRequest as $key => $oldFile) {
            if (!isset($files[$key])) continue;

            $this->unlink($oldFile);
            unset($filesFromRequest[$key]);
        }

        return array_merge($filesFromRequest, $files);
    }

    /**
     * Create File options
     *
     * @param  string $uploadedFile
     * @param  array<int|string, mixed> $options
     * @return array<int|string, mixed>
     */
    protected function fileOptions($uploadedFile, $options)
    {
        $fileOptions = [];
        if (array_key_exists('thumbnailImage', $options)) {
            $ImageResize = new ImageResize($uploadedFile);
            $thumbnailImage = $ImageResize->resize(
                $options['thumbnailImage']['width'],
                $options['thumbnailImage']['height'],
                $options['thumbnailImage']['quality']
            );
            $fileOptions['thumbnailImage'] = $thumbnailImage;
        }

        if (array_key_exists('mediumImage', $options)) {
            $ImageResize = new ImageResize($uploadedFile);
            $mediumImage = $ImageResize->resize(
                $options['mediumImage']['width'],
                $options['mediumImage']['height'],
                $options['mediumImage']['quality']
            );
            $fileOptions['mediumImage'] = $mediumImage;
        }
        return $fileOptions;
    }

    /**
     * Get the uploads storage directory name
     */
    protected function getUploadsStorageDirectoryName(): string
    {
        $baseDirectory = config('mongez.repository.uploads.uploadsDirectory', -1);

        if ($baseDirectory === -1) {
            $baseDirectory = 'data';
        }

        if ($baseDirectory) {
            $baseDirectory .= '/';
        }

        return $baseDirectory . (static::UPLOADS_DIRECTORY ?: static::NAME);
    }


    /**
     * Set date data
     *
     * @param  TModel $model
     * @param  array<int|string, mixed>|null $columns
     * @return void
     */
    protected function setDateData($model, $columns = null)
    {
        if (!$columns) {
            $columns = static::DATE_DATA;
        }

        foreach ((array) $columns as $input => $column) {
            if (is_numeric($input)) {
                $input = $column;
            }

            if ($this->isIgnorable($input)) continue;

            $date = $this->input($input);

            if (!$date) continue;

            $this->setToModel($model, $column, Date::parse($date));
        }
    }

    /**
     * Cast specific data automatically to int from the DATA array
     *
     * @param  TModel $model
     * @return void
     */
    protected function setIntData($model)
    {
        foreach (static::INTEGER_DATA as $input => $column) {
            if (is_numeric($input)) {
                $input = $column;
            }

            if ($this->isIgnorable($input)) continue;

            $this->setInt($model, $column, $this->input($input));
        }
    }

    /**
     * Cast specific data automatically to float from the DATA array
     *
     * @param  TModel $model
     * @return void
     */
    protected function setFloatData($model)
    {
        foreach (static::FLOAT_DATA as $input => $column) {
            if (is_numeric($input)) {
                $input = $column;
            }

            if ($this->isIgnorable($input)) continue;

            $this->setFloat($model, $column, $this->input($input));
        }
    }

    /**
     * Cast specific data automatically to bool from the DATA array
     *
     * @param  TModel $model
     * @return void
     */
    protected function setBoolData($model)
    {
        foreach (static::BOOLEAN_DATA as $input => $column) {
            if (is_numeric($input)) {
                $input = $column;
            }

            if ($this->isIgnorable($input)) continue;


            if (($inputValue = $this->input($input)) === 'false') {
                $this->setToModel($model, $column, false);
            } else {
                $this->setBool($model, $column, $inputValue);
            }
        }
    }

    /**
     * Set the given key/value to the model
     *
     * @param  TModel $model
     * @param  mixed $value
     * @return void
     */
    protected function setToModel($model, string $key, $value)
    {
        $model->setAttribute($key, $value);
    }

    /**
     * Set boolean value
     *
     * @param  TModel $model
     * @param  mixed $value
     * @return void
     */
    protected function setBool($model, string $key, $value)
    {
        $this->setToModel($model, $key, (bool) $value);
    }

    /**
     * Set int value
     *
     * @param  TModel $model
     * @param  mixed $value
     * @return void
     */
    protected function setInt($model, string $key, $value)
    {
        $this->setToModel($model, $key, (int) $value);
    }

    /**
     * Set float value
     *
     * @param  TModel $model
     * @param  mixed $value
     * @return void
     */
    protected function setFloat($model, string $key, $value)
    {
        $this->setToModel($model, $key, (float) $value);
    }

    /**
     * Check if the given input is ignorable
     */
    protected function isIgnorable(string $input): bool
    {
        return ($this->forceIgnore && !in_array($input, static::PATCHABLE_DATA)) ||
            ((static::WHEN_AVAILABLE_DATA === true || in_array($input, static::WHEN_AVAILABLE_DATA)) && $this->input($input) === null);
    }

    /**
     * Get input value
     *
     * @param  mixed $default
     * @return mixed
     */
    protected function input(string $key, $default = null)
    {
        return $this->request->has($key) ? $this->request->input($key) : ($this->request->__get($key) ?: $default);
    }

    /**
     * Get int input value
     *
     * @param  mixed $default
     */
    protected function intInput(string $key, $default = null): int
    {
        return (int) $this->input($key, $default);
    }

    /**
     * Get float input value
     *
     * @param  mixed $default
     */
    protected function floatInput(string $key, $default = null): float
    {
        return (float) $this->input($key, $default);
    }

    /**
     * Get a boolean value
     *
     * @param  mixed $default
     */
    public function boolInput(string $key, $default = null): bool
    {
        $value = $this->input($key, $default);

        if ($value === 'false') {
            $value = false;
        } elseif ($value === 'true') {
            $value = true;
        }

        return (bool) $value;
    }
}
