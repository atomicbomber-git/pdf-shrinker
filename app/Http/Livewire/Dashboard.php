<?php

namespace App\Http\Livewire;

use Illuminate\Http\File;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class Dashboard extends Component
{
    use WithFileUploads;

    public $files = [];
    public $pairedFiles = [];
    public $compress = true;
    public $output_filename;
    public $image_density = 120;
    public $quality = 20;

    public function submit()
    {
        $data = $this->validate([
            "pairedFiles" => ["required", "array"],
            "pairedFiles.*.file" => ["required", "file", "mimetypes:application/pdf,images"],
            "pairedFiles.*.order" => ["required", "numeric"],
            "output_filename" => ["nullable", function ($attribute, $value, $fail) {
                $exists = Storage::disk("local")
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

        $pdfUniteProcess = new Process([
            "pdfunite",
            ...$realFilePaths->toArray(),
            "/dev/stdout"
        ]);

        $pdfUniteProcess->run();

        if (!$pdfUniteProcess->isSuccessful()) {
            throw new ProcessFailedException($pdfUniteProcess);
        }

        $output = $pdfUniteProcess->getOutput();

        if ($data["compress"]) {
            $convertProcess = (new Process([
                "convert",
                "-density",
                sprintf("%dx%d", $data["image_density"], $data["image_density"]),
                "-quality",
                $data["quality"],
                "-compress",
                "jpeg",
                "-", /* Input from pipe */
                "-", /* Output to stdout */
            ]))->setInput($output);

            $convertProcess->run();

            if (!$convertProcess->isSuccessful()) {
                throw new ProcessFailedException($convertProcess);
            }

            $output = $convertProcess->getOutput();
        }

        \Storage::disk("local")
            ->put($outputFileName, (string) $output);
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
        Storage::disk("local")->delete(
            $filename,
        );
    }

    public function removeAllFiles()
    {
        collect(Storage::disk("local")->files())
            ->each(function ($filename) {
                Storage::disk("local")->delete(
                    $filename,
                );
            });
    }

    public function updated($attribute, $value)
    {
        if ($attribute === "files") {
            $this->pairedFiles = collect($this->files)
                ->sortBy(function (TemporaryUploadedFile $file) {
                    return (strlen($file->getClientOriginalName())) . $file->getClientOriginalName();
                })->values()
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

    public function render()
    {
        return view('livewire.dashboard', [
            "outputFiles" => \
                collect(Storage::disk("local")->files())
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

    public function humanFilesize($sizeInBytes)
    {
        $i = floor(log($sizeInBytes, 1024));
        return round($sizeInBytes / pow(1024, $i), [0, 0, 2, 2, 3][$i]) . ['B', 'kB', 'MB', 'GB', 'TB'][$i];
    }
}
