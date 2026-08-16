<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>{{ $storeName }} — Ao Vivo</title>
  <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    html,body{height:100%;overflow:hidden;font-family:Roboto,-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:14px;background:#f5f5f5;color:#333}

    /* ── App shell ───────────────────────────────────────────── */
    .app{position:fixed;top:0;left:0;right:0;bottom:0;display:flex;flex-direction:column;background:#f5f5f5}

    /* ── Mini top bar ────────────────────────────────────────── */
    .topbar{background:#EE4D2D;height:40px;padding:0 16px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
    .topbar-left{display:flex;align-items:center;gap:8px;color:#fff;font-size:13px;font-weight:600}
    .topbar-logo{font-weight:900;font-size:15px;letter-spacing:-.3px}
    .topbar-sep{opacity:.6;font-size:14px}
    .live-badge{display:inline-flex;align-items:center;gap:5px;background:rgba(0,0,0,.18);border:1px solid rgba(255,255,255,.35);border-radius:100px;padding:2px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.7px}
    .live-dot{width:6px;height:6px;border-radius:50%;background:#fff;animation:pulse 1.5s ease-in-out infinite}
    @keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}
    .topbar-right{display:flex;align-items:center;gap:8px}
    .mp-chip{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);border-radius:5px;padding:3px 10px;font-size:11px;font-weight:700;color:#fff}

    /* ── Board body ──────────────────────────────────────────── */
    .board-body{flex:1;min-height:0;padding:clamp(8px,1.5vw,20px) clamp(8px,2vw,24px) clamp(6px,1vw,16px);display:flex;flex-direction:column;overflow:hidden}

    /* ── Grid ────────────────────────────────────────────────── */
    .board-grid{flex:1;min-height:0;display:grid;grid-template-columns:1fr minmax(240px,320px);gap:clamp(6px,1vw,14px);height:100%}

    /* ── Left column ─────────────────────────────────────────── */
    .left-col{display:flex;flex-direction:column;gap:clamp(6px,1vw,12px);min-height:0;overflow:hidden}

    /* ── Metrics row ─────────────────────────────────────────── */
    .metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:clamp(5px,.8vw,10px);flex-shrink:0}
    .metric-card{background:#fff;border-radius:8px;padding:clamp(8px,1.2vw,14px);display:flex;align-items:center;gap:10px;box-shadow:0 1px 4px rgba(0,0,0,.06);border:1px solid #f0f0f0}
    .metric-icon{width:34px;height:34px;background:#fff2ee;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#EE4D2D;flex-shrink:0}
    .metric-icon svg{width:18px;height:18px}
    .metric-content{display:flex;flex-direction:column;gap:1px;min-width:0}
    .metric-label{font-size:clamp(10px,.9vw,12px);color:#999;white-space:nowrap}
    .metric-value{font-size:clamp(13px,1.3vw,18px);font-weight:700;color:#333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

    /* ── Trend chart ─────────────────────────────────────────── */
    .trend-chart{background:#fff;border-radius:8px;border:1px solid #f0f0f0;box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden}
    .trend-hdr{padding:10px 14px 0;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
    .trend-title{font-size:13px;font-weight:600;color:#333}
    .trend-update{font-size:10px;color:#bbb}
    .trend-canvas{flex:1;min-height:0;padding:4px 4px 8px}

    /* ── Top products ────────────────────────────────────────── */
    .top-products{background:#fff;border-radius:8px;border:1px solid #f0f0f0;box-shadow:0 1px 4px rgba(0,0,0,.06);flex-shrink:0}
    .top-products-hdr{padding:9px 14px 7px;border-bottom:1px solid #f8f8f8}
    .top-products-title{font-size:13px;font-weight:600;color:#333}
    .top-products-list{padding:2px 0}
    .top-product-item{display:flex;align-items:center;gap:8px;padding:clamp(5px,.8vw,8px) 14px;border-bottom:1px solid #fafafa;transition:background .15s}
    .top-product-item:last-child{border-bottom:none}
    .top-product-item:hover{background:#fff8f7}
    .top-rank{font-size:11px;font-weight:700;color:#EE4D2D;width:18px;flex-shrink:0;text-align:center}
    .top-img{width:30px;height:30px;object-fit:cover;border-radius:4px;flex-shrink:0;background:#f5f5f5}
    .top-img-ph{width:30px;height:30px;border-radius:4px;background:#fff2ee;flex-shrink:0;display:flex;align-items:center;justify-content:center}
    .top-info{flex:1;min-width:0;display:flex;flex-direction:column;gap:1px}
    .top-name{font-size:clamp(10px,.9vw,12px);color:#333;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .top-sales{font-size:10px;color:#999}
    .top-revenue{font-size:11px;font-weight:600;color:#EE4D2D;flex-shrink:0}

    /* ── Right column: live feed ─────────────────────────────── */
    .right-col{background:#fff;border-radius:8px;border:1px solid #f0f0f0;box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;flex-direction:column;min-height:0;overflow:hidden}
    .feed-hdr{padding:10px 14px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
    .feed-title{font-size:13px;font-weight:600;color:#333}
    .feed-live{display:flex;align-items:center;gap:5px;font-size:11px;font-weight:700;color:#EE4D2D;letter-spacing:.5px}
    .feed-live-dot{width:7px;height:7px;background:#EE4D2D;border-radius:50%;animation:pulse 1.5s ease-in-out infinite}
    .feed-list{flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;padding:3px 0;scrollbar-width:thin;scrollbar-color:#eee transparent}
    .feed-list::-webkit-scrollbar{width:4px}
    .feed-list::-webkit-scrollbar-thumb{background:#e8e8e8;border-radius:2px}

    /* ── Sale card ───────────────────────────────────────────── */
    .sale-card{display:flex;align-items:center;gap:8px;padding:clamp(6px,.9vw,10px) 12px;border-bottom:1px solid #fafafa;opacity:0;transform:translateY(-6px);transition:opacity .35s ease,transform .35s ease,background .2s}
    .sale-card:last-child{border-bottom:none}
    .sale-card.visible{opacity:1;transform:translateY(0)}
    .sale-card.new-sale{background:#fff8f7}
    .sale-img{width:36px;height:36px;object-fit:cover;border-radius:4px;flex-shrink:0;background:#f5f5f5}
    .sale-img-ph{width:36px;height:36px;border-radius:4px;background:#fff2ee;flex-shrink:0;display:flex;align-items:center;justify-content:center}
    .sale-info{flex:1;min-width:0;display:flex;flex-direction:column;gap:1px}
    .sale-produto{font-size:clamp(10px,.9vw,13px);color:#333;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sale-loja{font-size:11px;color:#EE4D2D;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .sale-local{font-size:10px;color:#bbb}
    .sale-right{display:flex;flex-direction:column;align-items:flex-end;gap:2px;flex-shrink:0}
    .sale-preco{font-size:clamp(11px,1vw,13px);font-weight:700;color:#EE4D2D;white-space:nowrap}
    .sale-tempo{font-size:10px;color:#bbb;white-space:nowrap}

    @media(max-width:900px){
      .board-grid{grid-template-columns:1fr;overflow-y:auto}
      .right-col{min-height:300px}
      .metrics{grid-template-columns:repeat(2,1fr)}
      .board-body{overflow-y:auto}
    }
  </style>
</head>
<body>
<div class="app">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="topbar-left">
      <span class="topbar-logo">Fornecefy</span>
      <span class="topbar-sep">›</span>
      <span>{{ $storeName }}</span>
      <span class="live-badge"><span class="live-dot"></span>Ao Vivo</span>
    </div>
    <div class="topbar-right">
      <span class="mp-chip">{{ $marketplace === 'mercado_livre' ? 'Mercado Livre' : 'Shopee' }}</span>
    </div>
  </div>

  <!-- BOARD BODY -->
  <div class="board-body">
    <div class="board-grid">

      <!-- LEFT COLUMN -->
      <div class="left-col">

        <!-- Metrics -->
        <div class="metrics">
          <div class="metric-card">
            <div class="metric-icon">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </div>
            <div class="metric-content">
              <span class="metric-label">Pedidos</span>
              <span class="metric-value" id="m-orders">0</span>
            </div>
          </div>
          <div class="metric-card">
            <div class="metric-icon">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="metric-content">
              <span class="metric-label">Receita</span>
              <span class="metric-value" id="m-revenue">R$ 0,00</span>
            </div>
          </div>
          <div class="metric-card">
            <div class="metric-icon">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/></svg>
            </div>
            <div class="metric-content">
              <span class="metric-label">Ticket Médio</span>
              <span class="metric-value" id="m-ticket">R$ 0,00</span>
            </div>
          </div>
        </div>

        <!-- Trend chart -->
        <div class="trend-chart">
          <div class="trend-hdr">
            <span class="trend-title">Tendência de Vendas</span>
            <span class="trend-update" id="chart-update"></span>
          </div>
          <div class="trend-canvas" id="chart-canvas"></div>
        </div>

        <!-- Top products -->
        <div class="top-products">
          <div class="top-products-hdr">
            <span class="top-products-title">Top {{ min(5, count($products)) }} Produtos à Venda</span>
          </div>
          <div class="top-products-list">
            @forelse($products as $i => $p)
            <div class="top-product-item">
              <span class="top-rank">#{{ $i+1 }}</span>
              @if(!empty($p['image_url']))
                <img class="top-img" src="{{ $p['image_url'] }}" alt="{{ $p['name'] }}" loading="lazy" onerror="this.style.display='none'"/>
              @else
                <div class="top-img-ph">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#EE4D2D" stroke-width="1.5" opacity=".5"><rect x="2" y="7" width="20" height="15" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2M8 7V5a2 2 0 0 0-4 0v2"/></svg>
                </div>
              @endif
              <div class="top-info">
                <span class="top-name">{{ $p['name'] }}</span>
                <span class="top-sales" id="top-sales-{{ $i }}">0 vendas</span>
              </div>
              @if(!empty($p['price']) && $p['price']>0)
                <span class="top-revenue">R$ {{ number_format($p['price']*3.5,2,',','.') }}</span>
              @endif
            </div>
            @empty
            <div style="text-align:center;padding:20px;color:#ccc;font-size:12px">Sem produtos cadastrados</div>
            @endforelse
          </div>
        </div>

      </div><!-- /left-col -->

      <!-- RIGHT COLUMN: live feed -->
      <div class="right-col">
        <div class="feed-hdr">
          <span class="feed-title">Vendas em Tempo Real</span>
          <span class="feed-live"><span class="feed-live-dot"></span>AO VIVO</span>
        </div>
        <div class="feed-list" id="feed-list"></div>
      </div>

    </div><!-- /board-grid -->
  </div><!-- /board-body -->

</div><!-- /app -->

<script>
(function() {
  // ── PHP config ────────────────────────────────────────────────────────────
  var REVENUE_MONTH = {{ (float)$revenue }};
  var ORDERS_DAY    = {{ (int)max(1, $ordersToday ?: max(1, round($monthlySales / 30))) }};
  var STORE_NAME    = {{ Js::from($storeName) }};
  var REVENUE_DAY   = REVENUE_MONTH / 30;

  var products = {!! json_encode(array_values(array_map(function($p) {
    return ['name' => $p['name'], 'price' => (float)($p['price'] ?? 0), 'image_url' => $p['image_url'] ?? '', 'link' => $p['link'] ?? ''];
  }, $products))) !!};

  if (!products.length) {
    products = [{name:'Produto',price:39.9,image_url:'',link:''},{name:'Item popular',price:59.9,image_url:'',link:''},{name:'Mais vendido',price:29.9,image_url:'',link:''}];
  }

  // ── Bell curve: peak around 15h ──────────────────────────────────────────
  function bell(h) { return Math.exp(-Math.pow(h-15,2)/45); }
  function cum(H) {
    var s=0, n=120, dh=H/n;
    for(var i=0;i<n;i++) s+=bell(i*dh+dh/2)*dh;
    return s;
  }
  var TOTAL = cum(24);

  function nowH() {
    var n=new Date(); return n.getHours()+n.getMinutes()/60+n.getSeconds()/3600;
  }
  function revAtH(H) { return REVENUE_DAY*(cum(H)/TOTAL); }
  function ordAtH(H) { return Math.max(0,Math.round(ORDERS_DAY*(cum(H)/TOTAL))); }

  // ── Format ────────────────────────────────────────────────────────────────
  function fBRL(v) { return 'R$ '+v.toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.'); }
  function fInt(v) { return Math.round(v).toLocaleString('pt-BR'); }

  // ── Metrics ───────────────────────────────────────────────────────────────
  var revDisp = revAtH(nowH()) * 0.97;

  function updateMetrics() {
    var h=nowH();
    var revT=revAtH(h);
    revDisp+=(revT-revDisp)*0.05;
    if(Math.abs(revT-revDisp)<0.01) revDisp=revT;
    var orders=ordAtH(h);
    var ticket=orders>0?revDisp/orders:0;
    document.getElementById('m-orders').textContent=fInt(orders);
    document.getElementById('m-revenue').textContent=fBRL(revDisp);
    document.getElementById('m-ticket').textContent=fBRL(ticket);
    // Top product sales
    var salesPerProd=Math.max(1,Math.round(orders/products.length));
    products.forEach(function(p,i){
      var el=document.getElementById('top-sales-'+i);
      if(el) el.textContent=fInt(salesPerProd*(products.length-i))+' vendas';
    });
  }

  // ── ECharts trend ─────────────────────────────────────────────────────────
  var chart = echarts.init(document.getElementById('chart-canvas'));

  function buildSeries(maxH, scale) {
    var data=[];
    for(var h=0;h<=24;h+=0.5) {
      if(h>maxH) break;
      data.push([h, (cum(h)/TOTAL)*REVENUE_DAY*scale]);
    }
    return data;
  }

  function updateChart() {
    var h=nowH();
    var todayData=buildSeries(h,1);
    var prevData=buildSeries(24,0.88);
    var hours=[];
    for(var i=0;i<=22;i+=2) hours.push(String(i).padStart(2,'0')+'h');

    chart.setOption({
      backgroundColor:'transparent',
      grid:{left:50,right:8,top:8,bottom:24,containLabel:false},
      xAxis:{type:'value',min:0,max:24,interval:2,
        axisLabel:{formatter:function(v){return String(v).padStart(2,'0')+'h';},fontSize:9,color:'#bbb'},
        axisLine:{show:false},axisTick:{show:false},splitLine:{show:false}},
      yAxis:{type:'value',axisLabel:{fontSize:9,color:'#bbb',formatter:function(v){return v>=1000?'R$'+(v/1000).toFixed(0)+'k':'R$'+v.toFixed(0);}},
        splitLine:{lineStyle:{color:'#f5f5f5'}}},
      series:[
        {name:'Anterior',type:'line',smooth:true,symbol:'none',
         data:prevData,
         lineStyle:{color:'#c0e8d5',width:1.5},
         areaStyle:{color:{type:'linear',x:0,y:0,x2:0,y2:1,colorStops:[{offset:0,color:'rgba(192,232,213,.2)'},{offset:1,color:'rgba(192,232,213,0)'}]}}},
        {name:'Hoje',type:'line',smooth:true,symbol:'circle',symbolSize:4,
         data:todayData,
         lineStyle:{color:'#EE4D2D',width:2},
         areaStyle:{color:{type:'linear',x:0,y:0,x2:0,y2:1,colorStops:[{offset:0,color:'rgba(238,77,45,.15)'},{offset:1,color:'rgba(238,77,45,0)'}]}}},
      ],
      tooltip:{trigger:'axis',formatter:function(p){
        return p.map(function(s){return s.seriesName+': '+fBRL(s.value[1]);}).join('<br/>');
      }}
    }, true);

    var now=new Date();
    document.getElementById('chart-update').textContent='Atualizado '+now.getHours().toString().padStart(2,'0')+':'+now.getMinutes().toString().padStart(2,'0');
  }

  window.addEventListener('resize', function(){ chart.resize(); });

  // ── Live sales feed ───────────────────────────────────────────────────────
  var CITIES = ['São Paulo, SP','Rio de Janeiro, RJ','Belo Horizonte, MG','Salvador, BA','Fortaleza, CE','Curitiba, PR','Manaus, AM','Recife, PE','Goiânia, GO','Porto Alegre, RS','Belém, PA','Guarulhos, SP','Campinas, SP','São Luís, MA','Maceió, AL','Natal, RN','Teresina, PI','Campo Grande, MS','Santos, SP','Osasco, SP','Ribeirão Preto, SP','Uberlândia, MG','Contagem, MG','Sorocaba, SP','Aracaju, SE','Feira de Santana, BA','Joinville, SC','Aparecida de Goiânia, GO','Ananindeua, PA','Duque de Caxias, RJ'];
  var FIRST = ['Ana','Carlos','Fernanda','Rafael','Juliana','Marcos','Patrícia','Lucas','Camila','Diego','Aline','Bruno','Vinícius','Larissa','Thiago','Beatriz','Felipe','Letícia','Eduardo','Natália','Rodrigo','Priscila','Gustavo','Mariana','Henrique','Renata','André','Isabela','Paulo','Bruna','Leandro','Ricardo','Bianca','Mateus','Vanessa','Daniel','Jéssica'];
  var LAST  = ['S.','F.','M.','C.','O.','R.','P.','A.','L.','B.','T.','D.','N.','V.','G.','H.'];

  function rand(arr){ return arr[Math.floor(Math.random()*arr.length)]; }
  function randProduct(){ return products[Math.floor(Math.random()*products.length)]; }
  function relTime(minutesAgo){ return minutesAgo<1?'agora':minutesAgo+'min atrás'; }

  var MAX_FEED = 25;

  function makeSaleCard(prod, city, minutesAgo, isNew) {
    var el = document.createElement('div');
    el.className = 'sale-card' + (isNew?' new-sale':'');
    var img = prod.image_url
      ? '<img class="sale-img" src="'+prod.image_url+'" onerror="this.parentNode.innerHTML=\'<div class=sale-img-ph></div>\'" loading="lazy"/>'
      : '<div class="sale-img-ph"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#EE4D2D" stroke-width="1.5" opacity=".4"><rect x="2" y="7" width="20" height="15" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2M8 7V5a2 2 0 0 0-4 0v2"/></svg></div>';
    var priceDisp = prod.price>0 ? fBRL(prod.price*3.5) : fBRL(29.9*3.5);
    var buyer = rand(FIRST)+' '+rand(LAST);
    el.innerHTML = img +
      '<div class="sale-info"><span class="sale-produto">'+prod.name+'</span>'+
      '<span class="sale-loja">'+buyer+' · '+STORE_NAME+'</span>'+
      '<span class="sale-local">'+city+'</span></div>'+
      '<div class="sale-right"><span class="sale-preco">'+priceDisp+'</span>'+
      '<span class="sale-tempo">'+relTime(minutesAgo)+'</span></div>';
    return el;
  }

  function addSale(isNew) {
    var feed = document.getElementById('feed-list');
    var prod = randProduct();
    var city = rand(CITIES);
    var el = makeSaleCard(prod, city, 0, !!isNew);
    feed.insertBefore(el, feed.firstChild);
    setTimeout(function(){ el.classList.add('visible'); }, 50);
    while(feed.children.length > MAX_FEED) feed.removeChild(feed.lastChild);
  }

  function seedFeed() {
    var orders = ordAtH(nowH());
    var count = Math.min(18, Math.max(5, Math.round(orders/2)));
    var feed = document.getElementById('feed-list');
    for(var i=0;i<count;i++) {
      var prod=randProduct(), city=rand(CITIES);
      var minutesAgo=Math.round((i+1)*(2+Math.random()*8));
      var el=makeSaleCard(prod, city, minutesAgo, false);
      feed.appendChild(el);
      setTimeout((function(e){ return function(){ e.classList.add('visible'); }; })(el), 80+i*30);
    }
  }

  function scheduleNextSale() {
    var delay = 4000 + Math.random()*14000; // 4-18 seconds
    setTimeout(function(){ addSale(true); scheduleNextSale(); }, delay);
  }

  // ── Boot ──────────────────────────────────────────────────────────────────
  updateMetrics();
  updateChart();
  seedFeed();

  setInterval(updateMetrics, 1000);
  setInterval(updateChart, 30000);

  // First new sale after 5-12 seconds
  setTimeout(function(){ addSale(true); scheduleNextSale(); }, 5000+Math.random()*7000);
})();
</script>
</body>
</html>
