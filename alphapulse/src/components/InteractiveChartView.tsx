import React, { useState } from "react";
import { StockItem } from "../types";
import { InteractiveCandleChart } from "./InteractiveCandleChart";
import { 
  TrendingUp, 
  Sparkles, 
  Layers, 
  CheckCircle2, 
  Clock, 
  Target, 
  ShieldAlert, 
  ChevronRight,
  Info,
  Sliders,
  Maximize2
} from "lucide-react";

interface Props {
  selectedStock: StockItem;
  stocks: StockItem[];
  onSelectStock: (stock: StockItem) => void;
  onOpenCopilotWithStock: (ticker: string) => void;
}

export const InteractiveChartView: React.FC<Props> = ({
  selectedStock,
  stocks,
  onSelectStock,
  onOpenCopilotWithStock
}) => {
  const [indicators, setIndicators] = useState({
    sma50: true,
    sma200: true,
    ema21: true,
    bollinger: false,
    volume: true,
    rsi: true,
    patternOverlay: true
  });

  const [timeframe, setTimeframe] = useState<"1D" | "1S" | "1M">("1D");

  return (
    <div className="space-y-5">
      {/* Top Ticker Selector Bar */}
      <div className="bg-white border-2 border-primary rounded-lg p-3 shadow-[3px_3px_0px_#1a1a1a] flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2 overflow-x-auto no-scrollbar py-1">
          <span className="text-xs font-headline font-bold text-neutral-500 uppercase tracking-wider whitespace-nowrap mr-1">
            Activos con Ruptura EOD:
          </span>
          {stocks.slice(0, 8).map((stock) => (
            <button
              key={stock.ticker}
              onClick={() => onSelectStock(stock)}
              className={`px-3 py-1 text-xs font-mono font-bold rounded border transition-all whitespace-nowrap ${
                selectedStock.ticker === stock.ticker
                  ? "bg-primary text-white border-primary shadow-[1px_1px_0px_#ffcc00]"
                  : "bg-[#f5f0e8] text-primary border-[#d0cbc3] hover:border-primary"
              }`}
            >
              {stock.ticker}
              <span className="ml-1 text-[10px] text-neutral-400">
                {stock.changePercent >= 0 ? `+${stock.changePercent.toFixed(1)}%` : `${stock.changePercent.toFixed(1)}%`}
              </span>
            </button>
          ))}
        </div>

        {/* Timeframe Selector */}
        <div className="flex items-center gap-1 bg-[#eee9e0] p-1 border border-primary rounded text-xs font-mono">
          {(["1D", "1S", "1M"] as const).map((tf) => (
            <button
              key={tf}
              onClick={() => setTimeframe(tf)}
              className={`px-2.5 py-1 rounded font-bold transition-all ${
                timeframe === tf
                  ? "bg-primary text-white"
                  : "text-neutral-600 hover:text-primary"
              }`}
            >
              {tf === "1D" ? "1D (Diario EOD)" : tf === "1S" ? "1S (Semanal)" : "1M (Mensual)"}
            </button>
          ))}
        </div>
      </div>

      {/* Main TradingView-Style Stage */}
      <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {/* Left 3 Columns: Interactive Chart Stage + Toolbar */}
        <div className="lg:col-span-3 space-y-4">
          {/* Header of Active Stock */}
          <div className="bg-white border-2 border-primary rounded-lg p-4 shadow-[3px_3px_0px_#1a1a1a] flex flex-wrap items-center justify-between gap-4">
            <div>
              <div className="flex items-center gap-3">
                <h2 className="font-headline font-black text-2xl text-primary">{selectedStock.ticker}</h2>
                <span className="text-xs font-mono font-semibold px-2 py-0.5 bg-[#eee9e0] text-primary rounded border border-neutral-300">
                  {selectedStock.exchange}
                </span>
                <span className="text-xs font-mono font-bold px-2 py-0.5 bg-emerald-100 text-emerald-900 rounded border border-emerald-300">
                  Patrón: {selectedStock.primaryPattern.name} ({selectedStock.primaryPattern.confidence}%)
                </span>
              </div>
              <p className="text-xs text-neutral-600 font-sans mt-0.5">
                {selectedStock.company} · {selectedStock.sector} · Market Cap: {selectedStock.marketCap}
              </p>
            </div>

            <div className="flex items-center gap-6">
              <div className="text-right">
                <div className="font-mono font-black text-2xl text-primary">
                  ${selectedStock.price.toFixed(2)}
                </div>
                <div className={`text-xs font-mono font-bold ${
                  selectedStock.changePercent >= 0 ? "text-emerald-700" : "text-rose-700"
                }`}>
                  {selectedStock.changePercent >= 0 ? `+${selectedStock.changeAmount.toFixed(2)} (+${selectedStock.changePercent.toFixed(2)}%)` : `${selectedStock.changeAmount.toFixed(2)} (${selectedStock.changePercent.toFixed(2)}%)`}
                </div>
              </div>

              <div className="hidden sm:block border-l border-[#e2ddd4] pl-4 text-xs font-mono space-y-0.5 text-neutral-600">
                <div>RVOL: <strong className="text-primary font-bold">{selectedStock.rvol.toFixed(1)}x</strong></div>
                <div>Volumen: <strong className="text-primary">{(selectedStock.volume / 1000000).toFixed(1)}M</strong></div>
                <div>Rango 52S: <span>${selectedStock.range52w.low} - ${selectedStock.range52w.high}</span></div>
              </div>
            </div>
          </div>

          {/* Indicator Toggles Toolbar */}
          <div className="bg-[#eee9e0] border-2 border-primary rounded-lg px-4 py-2 flex flex-wrap items-center justify-between gap-3 text-xs">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-headline font-bold text-neutral-700 flex items-center gap-1 mr-1">
                <Sliders className="w-3.5 h-3.5" />
                Indicadores:
              </span>

              <button
                onClick={() => setIndicators(prev => ({ ...prev, patternOverlay: !prev.patternOverlay }))}
                className={`px-2.5 py-1 rounded font-mono font-bold text-xs border transition-all ${
                  indicators.patternOverlay
                    ? "bg-[#ffcc00] text-primary border-primary shadow-[1px_1px_0px_#1a1a1a]"
                    : "bg-white text-neutral-600 border-neutral-300"
                }`}
              >
                Capa Chartismo (Pivote &amp; Targets)
              </button>

              <button
                onClick={() => setIndicators(prev => ({ ...prev, sma50: !prev.sma50 }))}
                className={`px-2 py-1 rounded font-mono font-semibold text-xs border transition-all ${
                  indicators.sma50
                    ? "bg-[#0055ff] text-white border-[#0055ff]"
                    : "bg-white text-neutral-600 border-neutral-300"
                }`}
              >
                SMA 50
              </button>

              <button
                onClick={() => setIndicators(prev => ({ ...prev, sma200: !prev.sma200 }))}
                className={`px-2 py-1 rounded font-mono font-semibold text-xs border transition-all ${
                  indicators.sma200
                    ? "bg-amber-600 text-white border-amber-600"
                    : "bg-white text-neutral-600 border-neutral-300"
                }`}
              >
                SMA 200
              </button>

              <button
                onClick={() => setIndicators(prev => ({ ...prev, ema21: !prev.ema21 }))}
                className={`px-2 py-1 rounded font-mono font-semibold text-xs border transition-all ${
                  indicators.ema21
                    ? "bg-pink-600 text-white border-pink-600"
                    : "bg-white text-neutral-600 border-neutral-300"
                }`}
              >
                EMA 21
              </button>

              <button
                onClick={() => setIndicators(prev => ({ ...prev, volume: !prev.volume }))}
                className={`px-2 py-1 rounded font-mono font-semibold text-xs border transition-all ${
                  indicators.volume
                    ? "bg-neutral-800 text-white border-neutral-800"
                    : "bg-white text-neutral-600 border-neutral-300"
                }`}
              >
                Volumen EOD
              </button>

              <button
                onClick={() => setIndicators(prev => ({ ...prev, rsi: !prev.rsi }))}
                className={`px-2 py-1 rounded font-mono font-semibold text-xs border transition-all ${
                  indicators.rsi
                    ? "bg-purple-600 text-white border-purple-600"
                    : "bg-white text-neutral-600 border-neutral-300"
                }`}
              >
                RSI (14)
              </button>
            </div>

            <button
              onClick={() => onOpenCopilotWithStock(selectedStock.ticker)}
              className="flex items-center gap-1.5 px-3 py-1 bg-[#ffdad6] hover:bg-[#ffb4ab] text-secondary border border-secondary rounded font-headline font-bold uppercase tracking-wider"
            >
              <Sparkles className="w-3.5 h-3.5" />
              <span>Analizar con IA</span>
            </button>
          </div>

          {/* Interactive SVG Candle Chart */}
          <InteractiveCandleChart stock={selectedStock} showIndicators={indicators} />
        </div>

        {/* Right Column: Chartist Pattern Diagnostics & Track Record */}
        <div className="space-y-4">
          {/* Card: Pattern Diagnostics */}
          <div className="bg-white border-2 border-primary rounded-lg p-5 shadow-[4px_4px_0px_#1a1a1a] space-y-4">
            <div className="border-b-2 border-primary pb-3">
              <div className="flex items-center justify-between text-xs font-mono text-neutral-600">
                <span>DIAGNÓSTICO ALGORÍTMICO</span>
                <span className="font-bold text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200">
                  {selectedStock.primaryPattern.timeframe} EOD
                </span>
              </div>
              <h3 className="font-headline font-black text-xl text-primary mt-1">
                {selectedStock.primaryPattern.name}
              </h3>
              <div className="flex items-center gap-2 mt-1">
                <div className="flex-1 h-2 bg-[#eee9e0] rounded-full overflow-hidden border border-neutral-300">
                  <div 
                    className="h-full bg-emerald-500 rounded-full"
                    style={{ width: `${selectedStock.primaryPattern.confidence}%` }}
                  />
                </div>
                <span className="text-xs font-mono font-bold text-primary">
                  {selectedStock.primaryPattern.confidence}% Confianza
                </span>
              </div>
            </div>

            {/* Pattern Description */}
            <p className="text-xs text-neutral-700 leading-relaxed font-sans">
              {selectedStock.primaryPattern.description}
            </p>

            {/* Execution Levels Box */}
            <div className="bg-[#faf7f2] border border-primary p-3 rounded space-y-2 text-xs font-mono">
              <div className="flex items-center justify-between">
                <span className="text-neutral-600 flex items-center gap-1">
                  <span className="w-2 h-2 rounded-full bg-amber-400"></span>
                  Punto Pivote (Trigger):
                </span>
                <strong className="text-primary">${selectedStock.primaryPattern.pivotPrice}</strong>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-neutral-600 flex items-center gap-1">
                  <span className="w-2 h-2 rounded-full bg-emerald-500"></span>
                  Objetivo Teórico:
                </span>
                <strong className="text-emerald-700">${selectedStock.primaryPattern.targetPrice}</strong>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-neutral-600 flex items-center gap-1">
                  <span className="w-2 h-2 rounded-full bg-rose-500"></span>
                  Stop Loss Técnico:
                </span>
                <strong className="text-rose-700">${selectedStock.primaryPattern.stopLoss}</strong>
              </div>
              <div className="pt-2 border-t border-neutral-200 flex items-center justify-between font-bold">
                <span>Ratio Riesgo / Beneficio (R:R):</span>
                <span className="text-emerald-800 bg-emerald-100 px-1.5 py-0.5 rounded">
                  1 : {selectedStock.primaryPattern.riskReward}
                </span>
              </div>
            </div>

            {/* Checklist */}
            <div className="space-y-2 pt-2 border-t border-[#eee9e0]">
              <span className="text-xs font-headline font-bold uppercase tracking-wider text-neutral-700 block">
                Checklist Cuantitativo:
              </span>
              {selectedStock.primaryPattern.checklist.map((item, idx) => (
                <div key={idx} className="flex items-center justify-between text-xs font-mono">
                  <span className="text-neutral-700 flex items-center gap-1.5 text-[11px]">
                    <CheckCircle2 className={`w-3.5 h-3.5 shrink-0 ${
                      item.status === "VALIDADO" ? "text-emerald-600" : "text-amber-500"
                    }`} />
                    <span>{item.label}</span>
                  </span>
                  <span className={`text-[9px] font-bold px-1.5 py-0.5 rounded whitespace-nowrap ${
                    item.status === "VALIDADO" ? "bg-emerald-100 text-emerald-800" : "bg-amber-100 text-amber-800"
                  }`}>
                    {item.status}
                  </span>
                </div>
              ))}
            </div>
          </div>

          {/* Card: Past Track Record */}
          <div className="bg-white border-2 border-primary rounded-lg p-5 shadow-[4px_4px_0px_#1a1a1a] space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-headline font-bold uppercase tracking-wider text-primary">
                Historial de Señales en {selectedStock.ticker}
              </span>
              <Clock className="w-3.5 h-3.5 text-neutral-400" />
            </div>

            <div className="space-y-2 text-xs font-mono">
              {(selectedStock.trackRecord || [
                { pattern: "Cup & Handle Breakout", date: "15 Oct 2023", entry: selectedStock.price * 0.72, gainPercent: 34.2, days: 45 },
                { pattern: "Golden Cross 50/200", date: "12 May 2023", entry: selectedStock.price * 0.58, gainPercent: 52.8, days: 90 }
              ]).map((sig, idx) => (
                <div key={idx} className="p-2.5 bg-[#f5f0e8] border border-primary rounded flex items-center justify-between">
                  <div>
                    <strong className="text-primary block font-headline">{sig.pattern}</strong>
                    <span className="text-[10px] text-neutral-500">{sig.date} · Entrada: ${sig.entry.toFixed(2)}</span>
                  </div>
                  <div className="text-right">
                    <span className="text-emerald-700 font-bold block">+{sig.gainPercent}%</span>
                    <span className="text-[10px] text-neutral-500">{sig.days} sesiones</span>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};
