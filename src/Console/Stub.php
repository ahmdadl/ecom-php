<?php

namespace HZ\Illuminate\Mongez\Console;

use Illuminate\Support\Facades\App;
use Illuminate\Filesystem\Filesystem;

class Stub implements \Stringable
{
    public string $content;
    /**
     * Stub Path
     */
    protected string $path;

    /**
     * File Manager
     */
    protected Filesystem $files;

    /**
     * Constructor
     */
    public function __construct(string $path)
    {
        $this->path = $path;

        $this->files = App::make(Filesystem::class);

        $content = $this->files->get($this->path);

        // replace \r and \n\r with php eol value
        $content = str_replace(["\r\n", "\n"], PHP_EOL, $content);

        $this->content = $content;
    }

    /**
     * Replace the given data
     *
     * @param array<string, mixed> $replacements
     */
    public function replace(array $replacements): Stub
    {
        $this->content = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $this->content
        );

        return $this;
    }

    /**
     * Mutate the given array to string
     *
     * @param string|array<int, string> $data
     */
    public function stringAsArray($data): string
    {
        if (is_string($data)) {
            $data = explode(',', $data);
        }

        $flattenVData = implode(', ', array_map(fn($value) => "'" . $value . "'", $data));

        return '[' . $flattenVData . ']';
    }


    /**
     * Return proper value for data that will be replaced
     * If the given data is empty, then return tab indent with double slash
     *
     * @param array<int, string> $data
     */
    public function data(array $data): string
    {
        return $data ? implode(PHP_EOL, $data) : "\t//";
    }

    /**
     * Add tab indent then append the given text
     */
    public function tabWith(string $text): string
    {
        return "\t" . $text;
    }

    /**
     * Remove the given text line
     */
    public function removeLine(string $lineText, bool $firstMatchedLineOnly = true): Stub
    {
        $content = '';

        foreach (explode(PHP_EOL, $this->content) as $line) {
            if ($this->areMatchedLines($lineText, $line)) {
                if ($firstMatchedLineOnly) {
                    break;
                } else {
                    continue;
                }
            }

            $content .= $line . PHP_EOL;
        }

        $this->content = $content;

        return $this;
    }

    /**
     * Append the given content after php tag
     */
    public function appendAfterPHPTag(string $content): Stub
    {
        return $this->appendAfter('<?php', $content);
    }

    /**
     * Add the given content after the given search line
     */
    public function appendAfter(string $searchLineText, string $newContent): Stub
    {
        $content = '';

        $lines = explode(PHP_EOL, $this->content);

        $lines = array_map(fn($line) => rtrim($line, "\r"), $lines);

        foreach ($lines as $line) {
            $content .= $line . PHP_EOL;

            if ($this->areMatchedLines($searchLineText, $line)) {
                $content .= $newContent . PHP_EOL;
            }
        }

        $this->content = $content;

        return $this;
    }

    /**
     * Determine if the given two lines are matched
     */
    protected function areMatchedLines(string $lineOne, string $lineTwo, bool $removeWhiteSpaces = true): bool
    {
        if ($removeWhiteSpaces) {
            return $this->trim($lineOne) === $this->trim($lineTwo);
        }

        return $lineOne === $lineTwo;
    }

    /**
     * Remove whitespace tab indents and line break from the given text
     */
    protected function trim(string $text): string
    {
        $pattern = '/^\s\s+|\s\s+$/';

        return (string) preg_replace($pattern, '', $text);
    }

    /**
     * Save the content to the given path
     *
     * @return void
     */
    public function saveTo(string $path)
    {
        $directory = dirname($path);

        if (!$this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true, true);
        }

        // for some freaking unknown reason, the admin.php routes file duplicating lines
        // so this is just a jerk workaround to fix the issue for the time being
        if (str_contains($this->content, ' Admin Routes')) {
            $lines = explode(PHP_EOL, $this->content);
            foreach ($lines as $index => $line) {
                $line = str_replace("\r", '', $line);
                $lines[$index] = $line;
            }
            $this->content = implode(PHP_EOL, $lines);
        }

        $this->files->put($path, $this->content);
    }

    /**
     * {@inherit}
     */
    public function __toString(): string
    {
        return (string) $this->content;
    }
}
