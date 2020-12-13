<?php

namespace App\Http\Livewire;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class Dashboard extends Component
{
    use WithFileUploads;

    public $files = [];
    public array $pairedFiles = [];
    public bool $compress = true;
    public string $output_filename;
    public $image_density = 120;
    public $quality = 20;
    public $current_preview = null;

    public function submit()
    {
        $data = $this->validate([
            "pairedFiles" => ["required", "array"],
            "pairedFiles.*.file" => ["required", "file", "mimetypes:application/pdf,image/*"],
            "pairedFiles.*.order" => ["required", "numeric"],
            "output_filename" => ["nullable", function ($attribute, $value, $fail) {
                $exists = $this->getDisk()
                    ->exists($value . ".pdf");
                if ($exists) {
                    $fail("Name already exists.");
                }
            }],
            "compress" => ["required", "boolean"],
            "image_density" => ["required", "numeric", "gte:1"],
            "quality" => ["required", "numeric", "gte:1", "lte:100"],
        ], null, [
            'pairedFiles' => "files",
            "pairedFiles.*.file" => "file",
            "pairedFiles.*.order" => "order",
        ]);

        $outputFileName = ($data["output_filename"] ?? \Str::random()) . ".pdf";

        $realFilePaths = collect($data["pairedFiles"])
            ->filter(function ($pairedFile) {
                return !$pairedFile["is_removed"];
            })
            ->sortBy(function ($pairedFile) {
                return $pairedFile["order"] . $pairedFile["file"]->getClientOriginalName();
            })
            ->map(function ($pairedFile) {
                return $pairedFile["file"]->getRealPath();
            });

        $convertProcess = (new Process([
            "convert",
            ...($data["compress"] ?  $this->getConvertCompressArgs($data) : []),
            ...$realFilePaths->toArray(),
            "pdf:-", /* Output to stdout */
        ]));

        $convertProcess->run();

        if (!$convertProcess->isSuccessful()) {
            throw new ProcessFailedException($convertProcess);
        }

        $output = $convertProcess->getOutput();

        \Storage::disk("local")
            ->put($outputFileName, (string)$output);
    }

    /**
     * @return Filesystem
     */
    public function getDisk(): Filesystem
    {
        return Storage::disk("local");
    }

    public function toggleFileIsRemoved($keyToRemove)
    {
        $this->pairedFiles[$keyToRemove]["is_removed"] = !$this->pairedFiles[$keyToRemove]["is_removed"];
    }

    public function downloadFile($filename)
    {
        return response()->download(
            new File(storage_path("app/{$filename}"))
        );
    }

    public function removeFile($filename)
    {
        $this->getDisk()->delete(
            $filename,
        );
    }

    public function removeAllFiles()
    {
        collect($this->getDisk()->files())
            ->each(function ($filename) {
                $this->getDisk()->delete(
                    $filename,
                );
            });
    }

    public function displayPreview($index)
    {
        $options = $this->validate([
            "compress" => ["required", "boolean"],
            "image_density" => ["required", "numeric", "gte:1"],
            "quality" => ["required", "numeric", "gte:1", "lte:100"],
        ]);


        /** @var TemporaryUploadedFile $file */
        $file = $this->pairedFiles[$index]["file"];

        if (Str::is("image/*", $file->getMimeType())) {
            $this->current_preview = base64_encode(
                file_get_contents($file->getRealPath())
            );
        } elseif ($file->getMimeType() === "application/pdf") {
            $this->current_preview = base64_encode(
                $this->firstPageRawImageDataFromPdfDocument($file, $options)
            );
        }

        $this->emit("preview");
    }

    public function firstPageRawImageDataFromPdfDocument(UploadedFile $documentFile, $options = [])
    {
        $pdfSeparateProcess = new Process([
            "pdfseparate",
            "-f", 1,
            "-l", 1,
            $documentFile->getRealPath(),
            "/dev/stdout"
        ]);

        $pdfSeparateProcess->run();

        if (!$pdfSeparateProcess->isSuccessful()) {
            throw new ProcessFailedException($pdfSeparateProcess);
        }

        $pdfToImageProcess = (new Process([
            "convert",
            ...(
                ($options["compress"] ?? false) ?
                    $this->getConvertCompressArgs($options):
                    []
            ),
            "-", /* piped input */
            "jpeg:-", /* output to stdout */
        ]))->setInput(
            $pdfSeparateProcess->getOutput()
        );
        $pdfToImageProcess->run();

        if (!$pdfToImageProcess->isSuccessful()) {
            throw new ProcessFailedException($pdfToImageProcess);
        }

        return $pdfToImageProcess->getOutput();
    }

    public function updated($attribute, $value)
    {
        if ($attribute === "files") {
            $this->pairedFiles = collect($this->files)
                ->sortBy(fn(TemporaryUploadedFile $file) => $file->getClientOriginalName(), SORT_NATURAL)
                ->values()
                ->map(function (TemporaryUploadedFile $file, $index) {
                    return [
                        "file" => $file,
                        "size" => $this->humanFilesize($file->getSize()),
                        "order" => $index + 1,
                        "is_removed" => false,
                    ];
                })->toArray();
        }
    }

    public function humanFilesize($sizeInBytes)
    {
        $i = floor(log($sizeInBytes, 1024));
        return round($sizeInBytes / pow(1024, $i), [0, 0, 2, 2, 3][$i]) . ['B', 'kB', 'MB', 'GB', 'TB'][$i];
    }

    public function render()
    {
        return view('livewire.dashboard', [
            "outputFiles" => \
                collect($this->getDisk()->files())
                ->map(function ($filepath) {
                    return new File(storage_path("app/{$filepath}"));
                })
                ->filter(function (File $file) {
                    return $file->extension() === "pdf";
                })
                ->map(function (File $file) {
                    return [
                        "file" => $file,
                        "created_at" => Date::createFromTimestamp($file->getCTime()),
                        "size" => $this->humanFilesize($file->getSize()),
                    ];
                })->sortByDesc(function ($fileData) {
                    return $fileData["created_at"];
                })
        ]);
    }

    /**
     * @param array $data
     * @return array
     */
    public function getConvertCompressArgs(array $data): array
    {
        return [
            "-density", sprintf("%dx%d", $data["image_density"], $data["image_density"]),
            "-quality", $data["quality"],
            "-compress", "jpeg"
        ];
    }
}
