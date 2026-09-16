import React, { useState, useRef } from "react";
import { StockItem, Candle } from "../types";

interface Props {
  stock: StockItem;
  showIndicators: {
    sma50: boolean;
    sma200: boolean;
    ema21: boolean;
    bollinger: boolean;
    volume: boolean;
    rsi: boolean;
    patternOverlay: boolean;
  };
}

export const InteractiveCandleChart: React.FC<Props> = ({ stock, showIndicators }) => {
  const candles: Candle[] = stock.historicalCandles || [];
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  if (candles.length === 0) {
    return (
      <div className="h-96 flex items-center justify-center bg-white border-2 border-primary rounded-lg">
        <p className="font-mono text-sm text-neutral-500">Cargando datos de velas EOD...</p>
      </div>
    );
  }

  // Chart dimensions
  const width = 800;
  const height = 440;
  const padding = { top: 20, right: 65, bottom: 90, left: 10 };
  const chartWidth = width - padding.left - padding.right;
  const mainChartHeight = 250;
  const volumeChartTop = 275;
  const volumeChartHeight = 55;
  const rsiChartTop = 345;
  const rsiChartHeight = 55;

  // Price scale
  const prices = candles.flatMap(c => [c.high, c.low, c.sma50 || c.close, c.sma200 || c.close]);
  const minPrice = Math.min(...prices) * 0.98;
  const maxPrice = Math.max(...prices) * 1.02;
  const priceRange = maxPrice - minPrice || 1;

  const getY = (price: number) => {
    return padding.top + mainChartHeight - ((price - minPrice) / priceRange) * mainChartHeight;
  };

  // Volume scale
  const maxVolume = Math.max(...candles.map(c => c.volume)) || 1;
  const getVolumeY = (vol: number) => {
    return volumeChartTop + volumeChartHeight - (vol / maxVolume) * volumeChartHeight;
  };

  const candleWidth = Math.max(4, (chartWidth / candles.length) * 0.65);
  const slotWidth = chartWidth / candles.length;

  const activeCandle = hoveredIndex !== null && candles[hoveredIndex] ? candles[hoveredIndex] : candles[candles.length - 1];

  // Moving average SVG paths
  const sma50Points = candles
    .map((c, i) => {
      const price = c.sma50 || c.close * 0.95;
      const x = padding.left + i * slotWidth + slotWidth / 2;
      const y = getY(price);
      return `${x},${y}`;
    })
    .join(" ");

  const sma200Points = candles
    .map((c, i) => {
      const price = c.sma200 || c.close * 0.88;
      const x = padding.left + i * slotWidth + slotWidth / 2;
      const y = getY(price);
      return `${x},${y}`;
    })
    .join(" ");

  const ema21Points = candles
    .map((c, i) => {
      const price = c.close * 0.98;
      const x = padding.left + i * slotWidth + slotWidth / 2;
      const y = getY(price);
      return `${x},${y}`;
    })
    .join(" ");

  // Pattern overlay coordinates
  const pivotY = getY(stock.primaryPattern.pivotPrice);
  const stopY = getY(stock.primaryPattern.stopLoss);
  const targetY = getY(stock.primaryPattern.targetPrice);

  return (
    <div className="w-full bg-white border-2 border-primary rounded-lg overflow-hidden shadow-[4px_4px_0px_#1a1a1a]">
      {/* Candle Top Data Bar */}
      <div className="bg-[#f2ede5] border-b-2 border-primary px-4 py-2 flex flex-wrap items-center justify-between gap-3 text-xs font-mono">
        <div className="flex items-center gap-4">
          <span className="font-bold text-primary text-sm font-headline">{stock.ticker} - EOD 1D</span>
          <span>Fecha: <strong className="text-primary">{activeCandle.date}</strong></span>
          <span>O: <strong className="text-primary">${activeCandle.open.toFixed(2)}</strong></span>
          <span>H: <strong className="text-emerald-700">${activeCandle.high.toFixed(2)}</strong></span>
          <span>L: <strong className="text-rose-700">${activeCandle.low.toFixed(2)}</strong></span>
          <span>C: <strong className={activeCandle.close >= activeCandle.open ? "text-emerald-700" : "text-rose-700"}>${activeCandle.close.toFixed(2)}</strong></span>
          <span>Var: <strong className={activeCandle.close >= activeCandle.open ? "text-emerald-700" : "text-rose-700"}>
            {(((activeCandle.close - activeCandle.open) / activeCandle.open) * 100).toFixed(2)}%
          </strong></span>
        </div>
        <div className="flex items-center gap-3 text-neutral-600">
          <span>Volumen: <strong className="text-primary font-bold">{(activeCandle.volume / 1000000).toFixed(2)}M</strong></span>
          {showIndicators.sma50 && <span className="text-[#0055ff] font-semibold">SMA 50: ${(activeCandle.sma50 || activeCandle.close * 0.95).toFixed(2)}</span>}
          {showIndicators.sma200 && <span className="text-amber-600 font-semibold">SMA 200: ${(activeCandle.sma200 || activeCandle.close * 0.88).toFixed(2)}</span>}
        </div>
      </div>

      {/* Main SVG Container */}
      <div 
        ref={containerRef}
        className="relative w-full overflow-x-auto select-none cursor-crosshair bg-white"
        onMouseLeave={() => setHoveredIndex(null)}
      >
        <svg
          viewBox={`0 0 ${width} ${height}`}
          className="w-full h-auto min-w-[720px]"
          onMouseMove={(e) => {
            const rect = e.currentTarget.getBoundingClientRect();
            const svgX = ((e.clientX - rect.left) / rect.width) * width;
            const index = Math.floor((svgX - padding.left) / slotWidth);
            if (index >= 0 && index < candles.length) {
              setHoveredIndex(index);
            }
          }}
        >
          {/* Grid lines */}
          {[0, 0.25, 0.5, 0.75, 1].map((ratio) => {
            const y = padding.top + mainChartHeight * ratio;
            const price = maxPrice - ratio * priceRange;
            return (
              <g key={`grid-${ratio}`}>
                <line
                  x1={padding.left}
                  y1={y}
                  x2={width - padding.right}
                  y2={y}
                  stroke="#e2ddd4"
                  strokeDasharray="4,4"
                />
                <text
                  x={width - padding.right + 8}
                  y={y + 4}
                  fill="#737373"
                  fontSize="10"
                  fontFamily="monospace"
                >
                  ${price.toFixed(1)}
                </text>
              </g>
            );
          })}

          {/* Pattern Geometric Overlay (e.g. Cup & Handle or Resistance) */}
          {showIndicators.patternOverlay && (
            <g>
              {/* Pivot line */}
              <line
                x1={padding.left}
                y1={pivotY}
                x2={width - padding.right}
                y2={pivotY}
                stroke="#ffcc00"
                strokeWidth="2.5"
                strokeDasharray="6,4"
              />
              <rect
                x={width - padding.right + 2}
                y={pivotY - 9}
                width="60"
                height="18"
                fill="#ffcc00"
                rx="3"
              />
              <text
                x={width - padding.right + 5}
                y={pivotY + 4}
                fill="#1a1a1a"
                fontSize="9"
                fontWeight="bold"
                fontFamily="monospace"
              >
                PIVOT ${stock.primaryPattern.pivotPrice}
              </text>

              {/* Target line */}
              <line
                x1={padding.left}
                y1={targetY}
                x2={width - padding.right}
                y2={targetY}
                stroke="#10b981"
                strokeWidth="1.5"
                strokeDasharray="4,3"
              />
              <rect
                x={width - padding.right + 2}
                y={targetY - 9}
                width="60"
                height="18"
                fill="#10b981"
                rx="3"
              />
              <text
                x={width - padding.right + 5}
                y={targetY + 4}
                fill="#ffffff"
                fontSize="9"
                fontWeight="bold"
                fontFamily="monospace"
              >
                OBJ ${stock.primaryPattern.targetPrice}
              </text>

              {/* Stop Loss line */}
              <line
                x1={padding.left}
                y1={stopY}
                x2={width - padding.right}
                y2={stopY}
                stroke="#ef4444"
                strokeWidth="1.5"
                strokeDasharray="4,3"
              />
              <rect
                x={width - padding.right + 2}
                y={stopY - 9}
                width="60"
                height="18"
                fill="#ef4444"
                rx="3"
              />
              <text
                x={width - padding.right + 5}
                y={stopY + 4}
                fill="#ffffff"
                fontSize="9"
                fontWeight="bold"
                fontFamily="monospace"
              >
                STOP ${stock.primaryPattern.stopLoss}
              </text>

              {/* Shaded Risk-Reward zone on right side */}
              <rect
                x={width - padding.right - 140}
                y={Math.min(targetY, pivotY)}
                width="140"
                height={Math.abs(targetY - pivotY)}
                fill="#10b981"
                fillOpacity="0.08"
              />
              <rect
                x={width - padding.right - 140}
                y={Math.min(pivotY, stopY)}
                width="140"
                height={Math.abs(pivotY - stopY)}
                fill="#ef4444"
                fillOpacity="0.08"
              />
            </g>
          )}

          {/* Indicator Lines */}
          {showIndicators.sma200 && (
            <polyline
              fill="none"
              stroke="#d97706"
              strokeWidth="1.8"
              points={sma200Points}
            />
          )}

          {showIndicators.sma50 && (
            <polyline
              fill="none"
              stroke="#0055ff"
              strokeWidth="2"
              points={sma50Points}
            />
          )}

          {showIndicators.ema21 && (
            <polyline
              fill="none"
              stroke="#ec4899"
              strokeWidth="1.5"
              strokeDasharray="3,2"
              points={ema21Points}
            />
          )}

          {/* Candlesticks */}
          {candles.map((candle, i) => {
            const isBull = candle.close >= candle.open;
            const x = padding.left + i * slotWidth + (slotWidth - candleWidth) / 2;
            const centerX = padding.left + i * slotWidth + slotWidth / 2;
            const yOpen = getY(candle.open);
            const yClose = getY(candle.close);
            const yHigh = getY(candle.high);
            const yLow = getY(candle.low);
            const bodyTop = Math.min(yOpen, yClose);
            const bodyHeight = Math.max(2, Math.abs(yClose - yOpen));
            const color = isBull ? "#10b981" : "#ef4444";

            return (
              <g key={`candle-${candle.date}-${i}`}>
                {/* Wick */}
                <line
                  x1={centerX}
                  y1={yHigh}
                  x2={centerX}
                  y2={yLow}
                  stroke={color}
                  strokeWidth="1.2"
                />
                {/* Body */}
                <rect
                  x={x}
                  y={bodyTop}
                  width={candleWidth}
                  height={bodyHeight}
                  fill={isBull ? "#10b981" : "#ef4444"}
                  stroke="#1a1a1a"
                  strokeWidth="0.8"
                  rx="1"
                />
              </g>
            );
          })}

          {/* Volume Sub-Chart */}
          {showIndicators.volume && (
            <g>
              <line
                x1={padding.left}
                y1={volumeChartTop - 5}
                x2={width - padding.right}
                y2={volumeChartTop - 5}
                stroke="#1a1a1a"
                strokeWidth="1"
              />
              <text
                x={padding.left + 5}
                y={volumeChartTop + 10}
                fill="#737373"
                fontSize="9"
                fontFamily="monospace"
              >
                VOLUMEN EOD (Barras Verdes = Comprador)
              </text>
              {candles.map((candle, i) => {
                const isBull = candle.close >= candle.open;
                const x = padding.left + i * slotWidth + (slotWidth - candleWidth) / 2;
                const y = getVolumeY(candle.volume);
                const h = volumeChartTop + volumeChartHeight - y;
                return (
                  <rect
                    key={`vol-${i}`}
                    x={x}
                    y={y}
                    width={candleWidth}
                    height={Math.max(1, h)}
                    fill={isBull ? "#10b981" : "#ef4444"}
                    opacity="0.75"
                  />
                );
              })}
            </g>
          )}

          {/* RSI(14) Sub-Chart */}
          {showIndicators.rsi && (
            <g>
              <line
                x1={padding.left}
                y1={rsiChartTop - 5}
                x2={width - padding.right}
                y2={rsiChartTop - 5}
                stroke="#1a1a1a"
                strokeWidth="1"
              />
              <text
                x={padding.left + 5}
                y={rsiChartTop + 10}
                fill="#737373"
                fontSize="9"
                fontFamily="monospace"
              >
                RSI (14) - Zona Neutral [30 - 70]
              </text>
              {/* 70 line */}
              <line
                x1={padding.left}
                y1={rsiChartTop + rsiChartHeight * 0.3}
                x2={width - padding.right}
                y2={rsiChartTop + rsiChartHeight * 0.3}
                stroke="#ef4444"
                strokeDasharray="3,3"
                strokeWidth="0.8"
              />
              {/* 30 line */}
              <line
                x1={padding.left}
                y1={rsiChartTop + rsiChartHeight * 0.7}
                x2={width - padding.right}
                y2={rsiChartTop + rsiChartHeight * 0.7}
                stroke="#10b981"
                strokeDasharray="3,3"
                strokeWidth="0.8"
              />
              {/* RSI Polyline */}
              <polyline
                fill="none"
                stroke="#8b5cf6"
                strokeWidth="1.8"
                points={candles
                  .map((_, i) => {
                    const rsiVal = 45 + Math.sin(i * 0.4) * 22;
                    const x = padding.left + i * slotWidth + slotWidth / 2;
                    const y = rsiChartTop + rsiChartHeight - (rsiVal / 100) * rsiChartHeight;
                    return `${x},${y}`;
                  })
                  .join(" ")}
              />
            </g>
          )}

          {/* Hover Crosshair */}
          {hoveredIndex !== null && (
            <g>
              <line
                x1={padding.left + hoveredIndex * slotWidth + slotWidth / 2}
                y1={padding.top}
                x2={padding.left + hoveredIndex * slotWidth + slotWidth / 2}
                y2={height - 20}
                stroke="#1a1a1a"
                strokeWidth="1"
                strokeDasharray="4,4"
              />
              {/* Date tooltip badge at bottom */}
              <rect
                x={padding.left + hoveredIndex * slotWidth - 30}
                y={height - 18}
                width="60"
                height="16"
                fill="#1a1a1a"
                rx="2"
              />
              <text
                x={padding.left + hoveredIndex * slotWidth}
                y={height - 6}
                fill="#ffffff"
                fontSize="9"
                fontFamily="monospace"
                textAnchor="middle"
              >
                {candles[hoveredIndex].date.slice(5)}
              </text>
            </g>
          )}
        </svg>
      </div>

      {/* Bottom Chart Footer Legend */}
      <div className="px-4 py-2 bg-[#faf7f2] border-t-2 border-primary flex flex-wrap items-center justify-between text-xs text-neutral-600">
        <div className="flex items-center gap-4">
          <span className="flex items-center gap-1.5">
            <span className="w-3 h-0.5 bg-[#0055ff] inline-block"></span>
            <span>SMA 50</span>
          </span>
          <span className="flex items-center gap-1.5">
            <span className="w-3 h-0.5 bg-[#d97706] inline-block"></span>
            <span>SMA 200</span>
          </span>
          <span className="flex items-center gap-1.5">
            <span className="w-3 h-0.5 bg-[#ec4899] inline-block"></span>
            <span>EMA 21</span>
          </span>
          <span className="flex items-center gap-1.5">
            <span className="w-3 h-1 bg-[#ffcc00] inline-block"></span>
            <span>Pivote de Ruptura</span>
          </span>
        </div>
        <div className="font-mono text-[11px] text-neutral-500">
          * Datos al cierre de mercado (EOD Scraping 21:00 UTC). Sin retardo intradía.
        </div>
      </div>
    </div>
  );
};
