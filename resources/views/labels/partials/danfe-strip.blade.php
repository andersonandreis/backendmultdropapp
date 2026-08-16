{{-- NOV-208: DANFE Simplificado — faixa compacta no rodapé (padrão Shopee) --}}
<div class="danfe-strip" style="width:96mm;margin:0 auto;font-family:Arial,Helvetica,sans-serif;color:#000;border-top:1.2pt solid #000;padding-top:2pt;text-align:center">
    <div style="font-weight:bold;font-size:6.8pt">DANFE SIMPLIFICADO - ETIQUETA</div>
    <div style="font-size:5.8pt;margin-top:1.5pt">
        {{ $nota['tipo'] }} &nbsp;&nbsp; <b>NF:</b> {{ $nota['numero'] }} &nbsp;&nbsp; <b>Série:</b> {{ $nota['serie'] }} &nbsp;&nbsp; <b>Emissão:</b> {{ $nota['emissao'] }}
    </div>
    <div style="font-size:5.8pt;letter-spacing:.4pt;margin-top:1pt">{{ $nota['chave'] }}</div>
    <div class="danfe-strip-barcode" style="margin-top:1.5pt;line-height:0">{!! $nota['barcode_svg'] !!}</div>
</div>
