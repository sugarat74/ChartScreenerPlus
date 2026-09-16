import React, { useState } from "react";
import { StockItem, FilterState } from "../types";
import { 
  Search, 
  SlidersHorizontal, 
  BookmarkPlus, 
  TrendingUp, 
  Sparkles, 
  Layers, 
  LayoutGrid, 
  Table, 
  CheckCircle2, 
  ArrowUpRight, 
  ArrowDownRight,
  ShieldAlert,
  Star,
  Activity,
  BarChart2
} from "lucide-react";

interface ScreenerViewProps {
  stocks: StockItem[];
  filters: FilterState;
  setFilters: React.Dispatch<React.SetStateAction<FilterState>>;
  onSelectStock: (stock: StockItem) => void;
  onOpenCopilotWithStock: (ticker: string) => void;
  onToggleWatchlist: (ticker: string) => void;
  watchlistTickers: string[];
  onSaveScreenerPrompt: () => void;
}

export const ScreenerView: React.FC<ScreenerViewProps> = ({
  stocks,
  filters,
  setFilters,
  onSelectStock,
  onOpenCopilotWithStock,
  onToggleWatchlist,
  watchlistTickers,
  onSaveScreenerPrompt
}) => {
  const [viewMode, setViewMode] = useState<"table" | "parallel">("table");
  const [selectedStockForDrawer, setSelectedStockForDrawer] = useState<StockItem | null>(null);

  // Compute summary stats
  const breakoutsCount = stocks.filter(s => s.primaryPattern.name.toLowerCase().includes("cup") || s.primaryPattern.name.toLowerCase().includes("ruptura")).length;
  const highRvolCount = stocks.filter(s => s.rvol >= 2.0).length;
  const goldenCrossCount = stocks.filter(s => s.sma50 > s.sma200).length;

  return (
    <div className="space-y-6">
      {/* Top Quantitative Overview Metrics */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white border-2 border-primary p-4 rounded-lg shadow-[3px_3px_0px_#1a1a1a]">
          <div className="flex items-center justify-between text-xs text-neutral-600 font-mono">
            <span>RUPTURAS EOD ACTIVAS</span>
            <span className="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
          </div>
          <div className="flex items-baseline gap-2 mt-2">
            <span className="font-headline font-black text-3xl text-primary">{breakoutsCount}</span>
            <span className="text-xs font-mono text-emerald-700 font-bold bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200">
              Alta convicción
            </span>
          </div>
          <p className="text-[11px] text-neutral-500 mt-1">Patrones validados al cierre EOD</p>
        </div>

        <div className="bg-white border-2 border-primary p-4 rounded-lg shadow-[3px_3px_0px_#1a1a1a]">
          <div className="flex items-center justify-between text-xs text-neutral-600 font-mono">
            <span>VOLUMEN ANÓMALO (RVOL &gt; 2.0)</span>
            <Activity className="w-4 h-4 text-amber-600" />
          </div>
          <div className="flex items-baseline gap-2 mt-2">
            <span className="font-headline font-black text-3xl text-primary">{highRvolCount}</span>
            <span className="text-xs font-mono text-amber-700 font-bold bg-amber-50 px-1.5 py-0.5 rounded border border-amber-200">
              Acumulación
            </span>
          </div>
          <p className="text-[11px] text-neutral-500 mt-1">Flujo institucional confirmado</p>
        </div>

        <div className="bg-white border-2 border-primary p-4 rounded-lg shadow-[3px_3px_0px_#1a1a1a]">
          <div className="flex items-center justify-between text-xs text-neutral-600 font-mono">
            <span>CRUCES DORADOS (50/200)</span>
            <TrendingUp className="w-4 h-4 text-[#0055ff]" />
          </div>
          <div className="flex items-baseline gap-2 mt-2">
            <span className="font-headline font-black text-3xl text-primary">{goldenCrossCount}</span>
            <span className="text-xs font-mono text-[#0055ff] font-bold bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200">
              Tendencia Macro
            </span>
          </div>
          <p className="text-[11px] text-neutral-500 mt-1">Media 50 &gt; Media 200 días</p>
        </div>

        <div className="bg-white border-2 border-primary p-4 rounded-lg shadow-[3px_3px_0px_#1a1a1a]">
          <div className="flex items-center justify-between text-xs text-neutral-600 font-mono">
            <span>S&amp;P 500 EOD BENCHMARK</span>
            <BarChart2 className="w-4 h-4 text-emerald-600" />
          </div>
          <div className="flex items-baseline gap-2 mt-2">
            <span className="font-headline font-black text-2xl text-primary">5,638.20</span>
            <span className="text-xs font-mono text-emerald-700 font-bold bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200">
              +0.42%
            </span>
          </div>
          <p className="text-[11px] text-neutral-500 mt-1">Sesión alcista en mercado primario</p>
        </div>
      </div>

      {/* Advanced Filter Box */}
      <div className="bg-white border-2 border-primary rounded-lg p-5 shadow-[4px_4px_0px_#1a1a1a] space-y-4">
        {/* Top Controls Row: Search + Universe + Preset Action */}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex flex-wrap items-center gap-3 flex-1 min-w-[280px]">
            {/* Search Input */}
            <div className="relative flex-1 min-w-[200px]">
              <Search className="w-4 h-4 text-neutral-400 absolute left-3 top-1/2 -translate-y-1/2" />
              <input
                type="text"
                value={filters.searchQuery}
                onChange={(e) => setFilters(prev => ({ ...prev, searchQuery: e.target.value }))}
                placeholder="Buscar por ticker, empresa o sector (ej. NVDA, Semiconductores)..."
                className="w-full pl-9 pr-3 py-2 text-xs font-mono bg-[#faf7f2] border-2 border-primary rounded focus:outline-none focus:bg-white"
              />
            </div>

            {/* Universe Selector */}
            <select
              value={filters.universe}
              onChange={(e) => setFilters(prev => ({ ...prev, universe: e.target.value }))}
              className="py-2 px-3 text-xs font-headline font-bold bg-[#faf7f2] border-2 border-primary rounded focus:outline-none cursor-pointer"
            >
              <option value="all">Todos los Universos (4,820 Activos)</option>
              <option value="sp500">S&amp;P 500 Index</option>
              <option value="nasdaq100">NASDAQ 100 Tech Leaders</option>
              <option value="russell2000">Russell 2000 Small Caps</option>
              <option value="ibex35">IBEX 35 &amp; Mercado Continuo</option>
            </select>

            {/* Pattern Filter */}
            <select
              value={filters.selectedPatterns[0] || "all"}
              onChange={(e) => {
                const val = e.target.value;
                setFilters(prev => ({
                  ...prev,
                  selectedPatterns: val === "all" ? [] : [val]
                }));
              }}
              className="py-2 px-3 text-xs font-headline font-bold bg-[#faf7f2] border-2 border-primary rounded focus:outline-none cursor-pointer"
            >
              <option value="all">Cualquier Patrón Chartista</option>
              <option value="cup">Cup and Handle (Taza con Asa)</option>
              <option value="golden">Golden Cross (Cruce Dorado)</option>
              <option value="doble">Doble Suelo / W Reversal</option>
              <option value="bandera">Bandera Alcista (Bull Flag)</option>
              <option value="rotura">Rotura de ATH / Resistencia</option>
              <option value="soporte">Soporte Dinámico SMA 50</option>
            </select>
          </div>

          {/* Quick Actions Right */}
          <div className="flex items-center gap-2">
            <button
              onClick={onSaveScreenerPrompt}
              className="flex items-center gap-1.5 px-3 py-2 bg-[#ffcc00] hover:bg-[#ffd633] text-primary border-2 border-primary rounded font-headline font-bold text-xs uppercase tracking-wider shadow-[2px_2px_0px_#1a1a1a] transition-all"
            >
              <BookmarkPlus className="w-3.5 h-3.5" />
              <span>Guardar Screener</span>
            </button>
          </div>
        </div>

        {/* Technical Filter Chips & Indicator Criteria */}
        <div className="pt-3 border-t border-[#e2ddd4] flex flex-wrap items-center gap-2">
          <span className="text-xs font-headline font-bold uppercase tracking-wider text-neutral-600 mr-1 flex items-center gap-1">
            <SlidersHorizontal className="w-3.5 h-3.5" />
            Criterios Técnicos:
          </span>

          {/* Price > SMA200 */}
          <button
            onClick={() => setFilters(prev => ({ ...prev, priceAboveSma200: !prev.priceAboveSma200 }))}
            className={`px-3 py-1 text-xs font-mono font-medium rounded-full border transition-all ${
              filters.priceAboveSma200
                ? "bg-primary text-white border-primary shadow-[1px_1px_0px_#ffcc00]"
                : "bg-[#f2ede5] text-neutral-700 border-neutral-300 hover:border-primary"
            }`}
          >
            Precio &gt; SMA 200
          </button>

          {/* Golden Cross */}
          <button
            onClick={() => setFilters(prev => ({ ...prev, goldenCrossRecent: !prev.goldenCrossRecent }))}
            className={`px-3 py-1 text-xs font-mono font-medium rounded-full border transition-all ${
              filters.goldenCrossRecent
                ? "bg-primary text-white border-primary shadow-[1px_1px_0px_#ffcc00]"
                : "bg-[#f2ede5] text-neutral-700 border-neutral-300 hover:border-primary"
            }`}
          >
            Golden Cross (SMA 50 &gt; 200)
          </button>

          {/* EMA21 > EMA55 */}
          <button
            onClick={() => setFilters(prev => ({ ...prev, ema21AboveEma55: !prev.ema21AboveEma55 }))}
            className={`px-3 py-1 text-xs font-mono font-medium rounded-full border transition-all ${
              filters.ema21AboveEma55
                ? "bg-primary text-white border-primary shadow-[1px_1px_0px_#ffcc00]"
                : "bg-[#f2ede5] text-neutral-700 border-neutral-300 hover:border-primary"
            }`}
          >
            EMA 21 &gt; EMA 55 (Momentum)
          </button>

          {/* MACD Bullish */}
          <button
            onClick={() => setFilters(prev => ({ ...prev, macdBullishCross: !prev.macdBullishCross }))}
            className={`px-3 py-1 text-xs font-mono font-medium rounded-full border transition-all ${
              filters.macdBullishCross
                ? "bg-primary text-white border-primary shadow-[1px_1px_0px_#ffcc00]"
                : "bg-[#f2ede5] text-neutral-700 border-neutral-300 hover:border-primary"
            }`}
          >
            MACD Alcista (&gt; Signal)
          </button>

          {/* Minimum RVOL Filter */}
          <div className="flex items-center gap-1.5 ml-auto bg-[#faf7f2] border border-primary px-2.5 py-1 rounded">
            <span className="text-xs font-headline font-bold text-neutral-700">Min RVOL:</span>
            {[1.0, 1.5, 2.0, 3.0].map((rv) => (
              <button
                key={rv}
                onClick={() => setFilters(prev => ({ ...prev, minRvol: prev.minRvol === rv ? 1.0 : rv }))}
                className={`text-[11px] font-mono px-1.5 py-0.5 rounded font-bold transition-all ${
                  filters.minRvol === rv
                    ? "bg-[#ffcc00] text-primary border border-primary"
                    : "text-neutral-500 hover:text-primary"
                }`}
              >
                {rv}x
              </button>
            ))}
          </div>
        </div>

        {/* View Layout & Sorting Bar */}
        <div className="pt-2 border-t border-[#f0ebe2] flex flex-wrap items-center justify-between gap-3 text-xs">
          <div className="flex items-center gap-3">
            <span className="font-mono text-neutral-600">
              Mostrando <strong className="text-primary">{stocks.length}</strong> de 4,820 símbolos
            </span>
            <span className="text-neutral-300">|</span>
            <div className="flex items-center gap-1">
              <span className="text-neutral-500 font-mono">Ordenar por:</span>
              <select
                value={filters.sortBy}
                onChange={(e) => setFilters(prev => ({ ...prev, sortBy: e.target.value as any }))}
                className="py-1 px-2 text-xs font-mono font-bold bg-[#faf7f2] border border-primary rounded cursor-pointer"
              >
                <option value="rvol_desc">Volumen Relativo (RVOL Mayor)</option>
                <option value="confidence_desc">Confianza Algorítmica</option>
                <option value="change_desc">% Variación Diaria</option>
                <option value="rsi_desc">Fuerza Relativa RSI</option>
              </select>
            </div>
          </div>

          {/* View Toggle: Table vs Parallel Multi-Symbol Grid */}
          <div className="flex items-center gap-1 bg-[#f2ede5] p-1 border border-primary rounded">
            <button
              onClick={() => setViewMode("table")}
              className={`flex items-center gap-1 px-2.5 py-1 rounded text-xs font-headline font-bold transition-all ${
                viewMode === "table"
                  ? "bg-white text-primary border border-primary shadow-[1px_1px_0px_#1a1a1a]"
                  : "text-neutral-600 hover:text-primary"
              }`}
            >
              <Table className="w-3.5 h-3.5" />
              <span>Tabla Detallada</span>
            </button>
            <button
              onClick={() => setViewMode("parallel")}
              className={`flex items-center gap-1 px-2.5 py-1 rounded text-xs font-headline font-bold transition-all ${
                viewMode === "parallel"
                  ? "bg-white text-primary border border-primary shadow-[1px_1px_0px_#1a1a1a]"
                  : "text-neutral-600 hover:text-primary"
              }`}
            >
              <LayoutGrid className="w-3.5 h-3.5" />
              <span>Análisis Paralelo (Grid)</span>
            </button>
          </div>
        </div>
      </div>

      {/* Main Content: Table Mode vs Parallel Multi-Symbol Grid */}
      {viewMode === "table" ? (
        <div className="bg-white border-2 border-primary rounded-lg overflow-x-auto shadow-[4px_4px_0px_#1a1a1a]">
          <table className="w-full text-left text-xs border-collapse">
            <thead className="bg-[#eee9e0] border-b-2 border-primary font-headline uppercase tracking-wider text-primary text-[11px]">
              <tr>
                <th className="py-3 px-4">Símbolo / Empresa</th>
                <th className="py-3 px-3">Cierre EOD</th>
                <th className="py-3 px-3">Var %</th>
                <th className="py-3 px-3">RVOL (EOD)</th>
                <th className="py-3 px-4">Patrón Chartista &amp; Confianza</th>
                <th className="py-3 px-3">Niveles (Pivote / Stop / Obj)</th>
                <th className="py-3 px-3">Medias (20/50/200)</th>
                <th className="py-3 px-3">RSI(14)</th>
                <th className="py-3 px-4 text-center">Tendencia (7D)</th>
                <th className="py-3 px-4 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#eee9e0] font-mono">
              {stocks.map((stock) => {
                const isWatched = watchlistTickers.includes(stock.ticker);
                const isPositive = stock.changePercent >= 0;

                return (
                  <tr
                    key={stock.ticker}
                    onClick={() => setSelectedStockForDrawer(stock)}
                    className="hover:bg-[#faf7f2] cursor-pointer transition-colors"
                  >
                    {/* Ticker & Company */}
                    <td className="py-3 px-4">
                      <div className="flex items-center gap-2">
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            onToggleWatchlist(stock.ticker);
                          }}
                          className={`p-1 rounded transition-colors ${
                            isWatched ? "text-amber-500 fill-amber-500" : "text-neutral-400 hover:text-amber-500"
                          }`}
                          title="Añadir a Radar de Seguimiento"
                        >
                          <Star className={`w-3.5 h-3.5 ${isWatched ? "fill-amber-400" : ""}`} />
                        </button>
                        <div>
                          <div className="font-headline font-black text-sm text-primary flex items-center gap-1.5">
                            {stock.ticker}
                            <span className="text-[10px] font-mono font-medium px-1 bg-[#eee9e0] text-neutral-600 rounded">
                              {stock.exchange}
                            </span>
                          </div>
                          <div className="text-[11px] text-neutral-500 font-sans truncate max-w-[140px]">
                            {stock.company}
                          </div>
                        </div>
                      </div>
                    </td>

                    {/* Price */}
                    <td className="py-3 px-3 font-bold text-primary text-sm">
                      ${stock.price.toFixed(2)}
                    </td>

                    {/* Change */}
                    <td className="py-3 px-3">
                      <span className={`inline-flex items-center gap-0.5 font-bold px-1.5 py-0.5 rounded text-[11px] ${
                        isPositive ? "bg-emerald-50 text-emerald-800 border border-emerald-200" : "bg-rose-50 text-rose-800 border border-rose-200"
                      }`}>
                        {isPositive ? <ArrowUpRight className="w-3 h-3" /> : <ArrowDownRight className="w-3 h-3" />}
                        {isPositive ? `+${stock.changePercent.toFixed(2)}%` : `${stock.changePercent.toFixed(2)}%`}
                      </span>
                    </td>

                    {/* RVOL */}
                    <td className="py-3 px-3">
                      <span className={`font-black text-xs px-2 py-0.5 rounded border ${
                        stock.rvol >= 3.0
                          ? "bg-[#ffcc00] text-primary border-primary font-mono shadow-[1px_1px_0px_#1a1a1a]"
                          : stock.rvol >= 2.0
                          ? "bg-amber-100 text-amber-900 border-amber-300"
                          : "bg-neutral-100 text-neutral-700 border-neutral-300"
                      }`}>
                        {stock.rvol.toFixed(1)}x
                      </span>
                    </td>

                    {/* Pattern & Confidence */}
                    <td className="py-3 px-4">
                      <div>
                        <span className="font-headline font-bold text-xs text-primary block">
                          {stock.primaryPattern.name}
                        </span>
                        <div className="flex items-center gap-2 mt-0.5">
                          <div className="w-16 h-1.5 bg-[#eee9e0] rounded-full overflow-hidden border border-neutral-300">
                            <div 
                              className="h-full bg-emerald-500 rounded-full"
                              style={{ width: `${stock.primaryPattern.confidence}%` }}
                            />
                          </div>
                          <span className="text-[10px] text-neutral-500 font-bold">
                            {stock.primaryPattern.confidence}%
                          </span>
                        </div>
                      </div>
                    </td>

                    {/* Technical Levels */}
                    <td className="py-3 px-3 text-[11px]">
                      <div>Pivote: <strong className="text-primary">${stock.primaryPattern.pivotPrice}</strong></div>
                      <div className="text-neutral-500">Stop: ${stock.primaryPattern.stopLoss} | Obj: ${stock.primaryPattern.targetPrice}</div>
                      <span className="text-emerald-700 font-semibold">R:R {stock.primaryPattern.riskReward}</span>
                    </td>

                    {/* Moving Averages */}
                    <td className="py-3 px-3 text-[10px] text-neutral-600">
                      <div className="flex items-center gap-1">
                        <span className="w-2 h-2 rounded-full bg-pink-500 inline-block"></span>
                        <span>EMA21: ${stock.ema21.toFixed(1)}</span>
                      </div>
                      <div className="flex items-center gap-1">
                        <span className="w-2 h-2 rounded-full bg-[#0055ff] inline-block"></span>
                        <span>SMA50: ${stock.sma50.toFixed(1)}</span>
                      </div>
                      <div className="flex items-center gap-1">
                        <span className="w-2 h-2 rounded-full bg-amber-600 inline-block"></span>
                        <span>SMA200: ${stock.sma200.toFixed(1)}</span>
                      </div>
                    </td>

                    {/* RSI */}
                    <td className="py-3 px-3">
                      <span className={`font-bold ${
                        stock.rsi14 >= 70 ? "text-amber-600" : stock.rsi14 <= 30 ? "text-emerald-600" : "text-neutral-700"
                      }`}>
                        {stock.rsi14.toFixed(1)}
                      </span>
                    </td>

                    {/* Sparkline */}
                    <td className="py-3 px-4 text-center">
                      <svg className="w-20 h-6 mx-auto overflow-visible" viewBox="0 0 80 24">
                        <polyline
                          fill="none"
                          stroke={isPositive ? "#10b981" : "#ef4444"}
                          strokeWidth="2"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                          points={stock.sparkline
                            .map((val, idx) => {
                              const min = Math.min(...stock.sparkline);
                              const max = Math.max(...stock.sparkline);
                              const range = max - min || 1;
                              const x = (idx / (stock.sparkline.length - 1)) * 80;
                              const y = 22 - ((val - min) / range) * 20;
                              return `${x},${y}`;
                            })
                            .join(" ")}
                        />
                      </svg>
                    </td>

                    {/* Actions */}
                    <td className="py-3 px-4 text-right">
                      <div className="flex items-center justify-end gap-1.5">
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            onSelectStock(stock);
                          }}
                          className="px-2 py-1 bg-white hover:bg-[#eee9e0] text-primary border border-primary rounded font-headline font-bold text-[10px] tracking-wider uppercase transition-colors"
                          title="Abrir Gráfico Interactivo"
                        >
                          Gráfico
                        </button>
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            onOpenCopilotWithStock(stock.ticker);
                          }}
                          className="p-1 bg-[#ffdad6] hover:bg-[#ffb4ab] text-secondary border border-secondary rounded transition-colors"
                          title="Refinar con Copilot IA"
                        >
                          <Sparkles className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ) : (
        /* Parallel Multi-Symbol Grid Analysis */
        <div className="space-y-3">
          <div className="flex items-center justify-between text-xs font-mono text-neutral-600 bg-[#eee9e0] p-3 border-2 border-primary rounded-lg">
            <span>MODO ANÁLISIS EN PARALELO MULTI-SÍMBOLO: Gráficos de velas y patrones sincronizados</span>
            <span className="font-bold text-primary">{stocks.length} activos en pantalla simultánea</span>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {stocks.map((stock) => {
              const isPositive = stock.changePercent >= 0;
              const isWatched = watchlistTickers.includes(stock.ticker);

              return (
                <div
                  key={stock.ticker}
                  className="bg-white border-2 border-primary rounded-lg p-4 shadow-[3px_3px_0px_#1a1a1a] hover:shadow-[5px_5px_0px_#1a1a1a] transition-all cursor-pointer flex flex-col justify-between"
                  onClick={() => onSelectStock(stock)}
                >
                  {/* Card Header */}
                  <div>
                    <div className="flex items-start justify-between">
                      <div>
                        <div className="flex items-center gap-2">
                          <span className="font-headline font-black text-lg text-primary">{stock.ticker}</span>
                          <span className="text-[10px] font-mono bg-[#eee9e0] px-1.5 py-0.5 rounded font-bold">
                            {stock.exchange}
                          </span>
                          <span className={`text-xs font-mono font-bold px-1.5 py-0.5 rounded ${
                            isPositive ? "bg-emerald-50 text-emerald-800" : "bg-rose-50 text-rose-800"
                          }`}>
                            {isPositive ? `+${stock.changePercent.toFixed(2)}%` : `${stock.changePercent.toFixed(2)}%`}
                          </span>
                        </div>
                        <p className="text-xs text-neutral-500 font-sans mt-0.5">{stock.company}</p>
                      </div>

                      <div className="text-right">
                        <span className="font-mono font-black text-base text-primary block">
                          ${stock.price.toFixed(2)}
                        </span>
                        <span className="font-mono text-[10px] text-amber-700 bg-amber-50 px-1 rounded border border-amber-200 font-bold">
                          RVOL: {stock.rvol.toFixed(1)}x
                        </span>
                      </div>
                    </div>

                    {/* Pattern Badge */}
                    <div className="mt-3 p-2 bg-[#f5f0e8] border border-primary rounded flex items-center justify-between text-xs">
                      <div>
                        <span className="font-headline font-bold text-primary block text-[11px]">
                          {stock.primaryPattern.name}
                        </span>
                        <span className="text-[10px] font-mono text-neutral-600">
                          Pivote: ${stock.primaryPattern.pivotPrice} | Obj: ${stock.primaryPattern.targetPrice}
                        </span>
                      </div>
                      <span className="bg-emerald-600 text-white font-mono text-[10px] font-bold px-1.5 py-0.5 rounded">
                        {stock.primaryPattern.confidence}%
                      </span>
                    </div>

                    {/* Parallel Mini Candlestick Simulation Box */}
                    <div className="mt-3 h-24 bg-[#faf7f2] border border-[#e2ddd4] rounded p-2 relative overflow-hidden flex items-end justify-between gap-1">
                      {/* Geometric Pivot Line */}
                      <div className="absolute top-4 left-0 right-0 border-b border-dashed border-amber-500 opacity-60"></div>
                      <span className="absolute top-1 right-2 text-[9px] font-mono text-amber-700 font-bold">
                        Pivote ${stock.primaryPattern.pivotPrice}
                      </span>

                      {/* Mini candles */}
                      {stock.historicalCandles?.slice(-16).map((c, i) => {
                        const isBull = c.close >= c.open;
                        const min = Math.min(...stock.historicalCandles!.slice(-16).map(b => b.low));
                        const max = Math.max(...stock.historicalCandles!.slice(-16).map(b => b.high));
                        const range = max - min || 1;
                        const heightPct = Math.max(12, ((c.high - c.low) / range) * 80);
                        const bottomPct = ((c.low - min) / range) * 60;

                        return (
                          <div
                            key={i}
                            className="flex-1 flex flex-col items-center justify-end relative h-full"
                          >
                            <div
                              className={`w-full max-w-[6px] rounded-xs transition-all ${
                                isBull ? "bg-emerald-500" : "bg-rose-500"
                              }`}
                              style={{ height: `${heightPct}%`, marginBottom: `${bottomPct}%` }}
                            />
                          </div>
                        );
                      })}
                    </div>
                  </div>

                  {/* Card Footer Actions */}
                  <div className="mt-4 pt-3 border-t border-[#eee9e0] flex items-center justify-between text-xs">
                    <div className="flex items-center gap-2 font-mono text-[11px] text-neutral-600">
                      <span>RSI: <strong>{stock.rsi14.toFixed(1)}</strong></span>
                      <span>R:R: <strong className="text-emerald-700">{stock.primaryPattern.riskReward}</strong></span>
                    </div>

                    <div className="flex items-center gap-1.5">
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          onOpenCopilotWithStock(stock.ticker);
                        }}
                        className="px-2 py-1 bg-[#ffdad6] hover:bg-[#ffb4ab] text-secondary border border-secondary rounded font-headline font-bold text-[10px] flex items-center gap-1"
                      >
                        <Sparkles className="w-3 h-3" />
                        <span>IA</span>
                      </button>

                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          onSelectStock(stock);
                        }}
                        className="px-2.5 py-1 bg-primary text-white hover:bg-neutral-800 rounded font-headline font-bold text-[10px] uppercase tracking-wider"
                      >
                        Ver Pro
                      </button>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Side Quick Drawer Modal when clicking a stock in Table */}
      {selectedStockForDrawer && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-xs">
          <div className="bg-white border-2 border-primary rounded-lg max-w-xl w-full p-6 shadow-[6px_6px_0px_#1a1a1a] space-y-4 max-h-[90vh] overflow-y-auto">
            {/* Header */}
            <div className="flex items-start justify-between border-b-2 border-primary pb-3">
              <div>
                <div className="flex items-center gap-2">
                  <h3 className="font-headline font-black text-2xl text-primary">{selectedStockForDrawer.ticker}</h3>
                  <span className="bg-[#ffcc00] text-primary text-xs font-mono font-bold px-1.5 py-0.5 rounded border border-primary">
                    {selectedStockForDrawer.primaryPattern.name}
                  </span>
                </div>
                <p className="text-xs text-neutral-600">{selectedStockForDrawer.company} - {selectedStockForDrawer.sector}</p>
              </div>
              <button
                onClick={() => setSelectedStockForDrawer(null)}
                className="w-7 h-7 bg-[#eee9e0] hover:bg-[#e2ddd4] border border-primary rounded flex items-center justify-center font-bold text-xs"
              >
                ✕
              </button>
            </div>

            {/* Pattern Quantitative Breakdown */}
            <div className="bg-[#faf7f2] border-2 border-primary p-4 rounded-lg space-y-3">
              <div className="flex items-center justify-between text-xs font-headline font-bold">
                <span className="uppercase text-neutral-700">Diagnóstico Algorítmico EOD:</span>
                <span className="text-emerald-700 font-mono font-bold">{selectedStockForDrawer.primaryPattern.confidence}% Confianza</span>
              </div>
              <p className="text-xs text-neutral-700 leading-relaxed font-sans">
                {selectedStockForDrawer.primaryPattern.description}
              </p>

              {/* Checklist */}
              <div className="space-y-1.5 pt-2 border-t border-[#e2ddd4]">
                {selectedStockForDrawer.primaryPattern.checklist.map((item, i) => (
                  <div key={i} className="flex items-center justify-between text-xs font-mono">
                    <span className="text-neutral-700 flex items-center gap-1.5">
                      <CheckCircle2 className={`w-3.5 h-3.5 ${item.status === "VALIDADO" ? "text-emerald-600" : "text-amber-500"}`} />
                      {item.label}
                    </span>
                    <span className={`text-[10px] font-bold px-1.5 rounded ${
                      item.status === "VALIDADO" ? "bg-emerald-100 text-emerald-800" : "bg-amber-100 text-amber-800"
                    }`}>
                      {item.status}
                    </span>
                  </div>
                ))}
              </div>
            </div>

            {/* Geometry stats */}
            {selectedStockForDrawer.primaryPattern.geometry && (
              <div className="grid grid-cols-3 gap-2 text-center text-xs font-mono">
                <div className="bg-[#eee9e0] p-2 rounded border border-primary">
                  <span className="text-[10px] text-neutral-500 block">DURACIÓN BASE</span>
                  <strong className="text-primary">{selectedStockForDrawer.primaryPattern.geometry.baseDuration}</strong>
                </div>
                <div className="bg-[#eee9e0] p-2 rounded border border-primary">
                  <span className="text-[10px] text-neutral-500 block">PROFUNDIDAD BASE</span>
                  <strong className="text-primary">{selectedStockForDrawer.primaryPattern.geometry.depthPercent}%</strong>
                </div>
                <div className="bg-[#eee9e0] p-2 rounded border border-primary">
                  <span className="text-[10px] text-neutral-500 block">VOLUMEN EXP</span>
                  <strong className="text-emerald-700">+{selectedStockForDrawer.primaryPattern.geometry.breakoutVolumeGainPercent}%</strong>
                </div>
              </div>
            )}

            {/* Drawer Actions */}
            <div className="pt-2 flex items-center justify-end gap-2">
              <button
                onClick={() => {
                  onOpenCopilotWithStock(selectedStockForDrawer.ticker);
                  setSelectedStockForDrawer(null);
                }}
                className="px-3 py-2 bg-[#ffdad6] hover:bg-[#ffb4ab] text-secondary border-2 border-secondary rounded font-headline font-bold text-xs uppercase flex items-center gap-1.5"
              >
                <Sparkles className="w-3.5 h-3.5" />
                <span>Refinar con Copilot IA</span>
              </button>

              <button
                onClick={() => {
                  onSelectStock(selectedStockForDrawer);
                  setSelectedStockForDrawer(null);
                }}
                className="px-4 py-2 bg-primary hover:bg-neutral-800 text-white border-2 border-primary rounded font-headline font-bold text-xs uppercase tracking-wider shadow-[2px_2px_0px_#ffcc00]"
              >
                Abrir Gráfico Completo
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
