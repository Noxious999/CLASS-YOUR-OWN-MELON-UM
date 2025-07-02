{{-- resources/views/partials/confusion-matrix-table.blade.php --}}
@php
    $labels = $labels ?? array_keys($matrix ?? []);
    $matrix = $matrix ?? [];
@endphp

<div class="table-responsive">
    <table class="table table-bordered cm-table text-center align-middle caption-top">
        <caption class="text-center text-muted small mb-1"><i class="fas fa-th-large me-1"></i>Matriks Konfusi</caption>
        <thead>
            <tr>
                <th style="width: 20%; background-color: #f8f9fa;">Aktual \ Prediksi</th>
                @foreach ($labels as $label)
                    <th scope="col" class="cm-label-positive">{{ ucfirst(str_replace('_', ' ', $label)) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($labels as $actualLabel)
                <tr>
                    <th scope="row" class="cm-label-positive text-end pe-3">{{ ucfirst(str_replace('_', ' ', $actualLabel)) }}</th>
                    @foreach ($labels as $predictedLabel)
                        @php
                            $value = $matrix[$actualLabel][$predictedLabel] ?? 0;
                            $isCorrect = $actualLabel === $predictedLabel;
                        @endphp
                        <td class="cm-value {{ $isCorrect ? 'cm-cell-correct' : 'cm-cell-incorrect' }}"
                            title="Aktual: {{ $actualLabel }}, Prediksi: {{ $predictedLabel }}">
                            {{ $value }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
