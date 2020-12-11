<div>
    <h1> PDF Shrinker by James </h1>

    <div class="card">
        <div class="card-body">
            <form wire:submit.prevent="submit">
                <div class="mb-3">
                    <div class="mb-3">
                        <label for="files"
                               class="form-label"
                        >
                            Files:
                        </label>

                        <input
                                x-ref="files"
                                @change="console.log($refs.files.files)"
                                wire:model="files"
                                multiple
                                accept="application/pdf,images"
                                class="form-control @error('pairedFiles') is-invalid @enderror"
                                type="file"
                                id="files"
                        >
                        @error("pairedFiles")
                        <span class="invalid-feedback">
                            {{ $message }}
                        </span>
                        @enderror

                        @error("pairedFiles.*")
                        <span class="invalid-feedback">
                            {{ $message }}
                        </span>
                        @enderror
                    </div>

                    <div class="mb-3">


                        <div class="form-check form-switch">
                            <input
                                    type="checkbox"
                                    wire:model="compress"
                                    placeholder="Compress?"
                                    class="form-check-input @error("compress") is-invalid @enderror"
                                    name="compress"
                                    value="{{ old("compress") }}"
                                    id="compress"
                            >
                            <label class="form-check-label"
                                   for="compress"
                            > Compress? </label>
                        </div>

                        @error("compress")
                        <span class="invalid-feedback">
                            {{ $message }}
                        </span>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label"
                               for="image_density"
                        > Image Density: </label>
                        <input
                                {{ !$compress ? "disabled" : ""  }}
                                wire:model="image_density"
                                id="image_density"
                                type="number"
                                min="0"
                                placeholder="Image Density"
                                class="form-control @error("image_density") is-invalid @enderror"
                                name="image_density"
                                value="{{ old("image_density") }}"
                        />
                        @error("image_density")
                        <span class="invalid-feedback">
                            {{ $message }}
                        </span>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label"
                               for="quality"
                        > Quality (0-100; lower means smaller size): </label>
                        <input
                                {{ !$compress ? "disabled" : ""  }}
                                wire:model="quality"
                                id="quality"
                                type="number"
                                min="0"
                                max="100"
                                step="1"
                                placeholder="Quality (0-100; lower means smaller size)"
                                class="form-control @error("quality") is-invalid @enderror"
                                name="quality"
                                value="{{ old("quality") }}"
                        />
                        @error("quality")
                        <span class="invalid-feedback">
                            {{ $message }}
                        </span>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label"
                               for="output_filename"
                        > *Output Filename: </label>
                        <input
                                id="output_filename"
                                wire:model="output_filename"
                                type="text"
                                placeholder="Output Filename"
                                class="form-control @error("output_filename") is-invalid @enderror"
                                name="output_filename"
                                value="{{ old("output_filename") }}"
                        />
                        <div class="form-text">
                            Keep this file empty to assign a random filename
                        </div>

                        @error("output_filename")
                        <span class="invalid-feedback">
                            {{ $message }}
                        </span>
                        @enderror
                    </div>

                    <table class="table table-sm table-striped table-hover">
                        <thead class="table-dark">
                        <tr>
                            <th> #</th>
                            <th> Filename</th>
                            <th> Filetype</th>
                            <th class="text-end"> Size</th>
                            <th class="text-end"> Order</th>
                            <th class="text-center"> Controls</th>
                        </tr>
                        </thead>

                        <tbody>
                        @foreach ($pairedFiles as $index => $pairedFile)
                            <tr wire:key="{{ $index }}"
                                class="{{ $pairedFile["is_removed"] ? 'table-dark' : '' }}"
                            >
                                <td> {{ $loop->iteration }} </td>
                                <td> {{ $pairedFile["file"]->getClientOriginalName() }} </td>
                                <td> {{ $pairedFile["file"]->guessExtension() }} </td>
                                <td class="text-end"> {{ $pairedFile["size"] }} </td>
                                <td class="text-end">
                                    <input
                                            {{ !$pairedFile["is_removed"] ? '' : 'disabled' }}
                                            id="pairedFiles.{{ $index }}.order"
                                            type="number"
                                            placeholder="Order"
                                            class="text-end form-control form-control-sm @error("pairedFiles.{$index}.order") is-invalid @enderror"
                                            wire:model="pairedFiles.{{ $index }}.order"
                                            name="pairedFiles.{{ $index }}.order"
                                    />
                                    @error("pairedFiles.{$index}.order")
                                    <span class="invalid-feedback">
                                            {{ $message }}
                                        </span>
                                    @enderror
                                </td>

                                <td class="text-center">
                                    <button
                                            wire:click="toggleFileIsRemoved({{ $index }})"
                                            class="btn {{ !$pairedFile["is_removed"] ? 'btn-danger' : 'btn-success' }} btn-sm"
                                            type="button"
                                    >
                                        {{ !$pairedFile["is_removed"] ? 'Remove' : 'Restore' }}
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-end">
                    <button class="btn btn-primary">
                        Process
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="d-flex justify-content-end my-3">
        <button
                x-data="{}"
                @click="window.confirm('Remove all files?') && $wire.call('removeAllFiles')"
                type="button"
                class="btn btn-danger"
        >
            Remove All Files
        </button>
    </div>

    @foreach ($outputFiles as $index => $outputFile)
        <div class="card my-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <h5> {{ $outputFile["file"]->getFilename() }} ({{ $outputFile["size"] }}
                        ) {{ $outputFile["created_at"]->diffForHumans() }} </h5>

                    <button wire:click="removeFile('{{ $outputFile["file"]->getFilename() }}')"
                            type="button"
                            class="btn btn-danger btn-sm"
                    >
                        Delete
                    </button>
                </div>

                <button
                        wire:click="downloadFile('{{ $outputFile["file"]->getFilename() }}')"
                        class="btn btn-link"
                >
                    Download
                </button>
            </div>
        </div>
    @endforeach
</div>