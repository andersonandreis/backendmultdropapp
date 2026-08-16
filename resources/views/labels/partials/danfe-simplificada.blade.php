{{-- NOV-208: DANFE Simplificado (modelo etiqueta, ref. UpSeller/Bling) gerado do XML da NF-e --}}
<div class="danfe-s">
    <div class="danfe-s-titulo">DANFE SIMPLIFICADO — ETIQUETA</div>
    <div class="danfe-s-barcode">{!! $nota['barcode_svg'] !!}</div>
    <div class="danfe-s-chave">{{ $nota['chave_formatada'] }}</div>
    <table class="danfe-s-grid">
        <tr>
            <td><strong>NF-e:</strong> Nº {{ $nota['numero'] }} · Série {{ $nota['serie'] }}</td>
            <td><strong>Emissão:</strong> {{ $nota['emissao'] }}</td>
        </tr>
        <tr>
            <td><strong>Tipo:</strong> {{ $nota['tipo'] }}</td>
            <td><strong>Valor total:</strong> R$ {{ number_format($nota['total'], 2, ',', '.') }}</td>
        </tr>
    </table>
    <div class="danfe-s-bloco">
        <strong>EMITENTE:</strong> {{ $nota['emit_nome'] }}<br>
        {{ $nota['emit_doc'] }}@if ($nota['emit_ie']) · IE {{ $nota['emit_ie'] }}@endif · {{ $nota['emit_uf'] }}
    </div>
    <div class="danfe-s-bloco">
        <strong>DESTINATÁRIO:</strong> {{ $nota['dest_nome'] }}@if ($nota['dest_doc']) · {{ $nota['dest_doc'] }}@endif<br>
        {{ $nota['dest_endereco'] }}
    </div>
    <table class="danfe-s-itens">
        <tr><th>Qtd</th><th>Descrição</th></tr>
        @foreach ($nota['itens'] as $item)
            <tr>
                <td>{{ rtrim(rtrim(number_format($item['qtd'], 2, ',', '.'), '0'), ',') }}</td>
                <td>{{ \Illuminate\Support\Str::limit($item['descricao'], 90) }}</td>
            </tr>
        @endforeach
    </table>
    <div class="danfe-s-protocolo">
        Protocolo de autorização: {{ $nota['protocolo'] }}@if ($nota['protocolo_data']) — {{ $nota['protocolo_data'] }}@endif
    </div>
    <div class="danfe-s-consulta">Consulta de autenticidade no portal nacional da NF-e: www.nfe.fazenda.gov.br/portal</div>
</div>
