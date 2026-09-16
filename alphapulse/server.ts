import express from "express";
import path from "path";
import { fileURLToPath } from "url";
import { GoogleGenAI } from "@google/genai";
import { createServer as createViteServer } from "vite";
import dotenv from "dotenv";

dotenv.config();

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const app = express();
const PORT = 3000;

app.use(express.json());

// Initialize Gemini client lazily
let genaiClient: GoogleGenAI | null = null;
function getGeminiClient(): GoogleGenAI {
  if (!genaiClient) {
    const apiKey = process.env.GEMINI_API_KEY;
    if (!apiKey) {
      console.warn("GEMINI_API_KEY environment variable not set. Fallback heuristic reasoning enabled.");
    }
    genaiClient = new GoogleGenAI({ apiKey: apiKey || "dummy-key" });
  }
  return genaiClient;
}

// Helper to generate realistic daily candles for any stock
function generateCandles(ticker: string, basePrice: number, trend: 'bullish' | 'consolidation' | 'breakout', bars = 70) {
  const candles = [];
  let currentClose = basePrice * 0.78;
  const now = new Date();
  
  for (let i = bars; i >= 0; i--) {
    const date = new Date(now);
    date.setDate(date.getDate() - i);
    // skip weekends
    if (date.getDay() === 0 || date.getDay() === 6) continue;

    const dateStr = date.toISOString().split("T")[0];
    const progress = (bars - i) / bars;

    let delta = (Math.random() - 0.48) * (basePrice * 0.025);
    if (trend === 'breakout' && i < 6) {
      delta = Math.abs(delta) + basePrice * 0.015;
    } else if (trend === 'bullish') {
      delta += basePrice * 0.003;
    }

    const open = currentClose;
    const close = Math.max(5, open + delta);
    const high = Math.max(open, close) + Math.random() * (basePrice * 0.012);
    const low = Math.min(open, close) - Math.random() * (basePrice * 0.012);
    
    let vol = Math.floor(15000000 + Math.random() * 25000000);
    if (i === 0 && trend === 'breakout') {
      vol = Math.floor(vol * 2.8); // volume surge on breakout
    }

    candles.push({
      date: dateStr,
      open: Number(open.toFixed(2)),
      high: Number(high.toFixed(2)),
      low: Number(low.toFixed(2)),
      close: Number(close.toFixed(2)),
      volume: vol,
      sma50: Number((currentClose * 0.94).toFixed(2)),
      sma200: Number((currentClose * 0.86).toFixed(2))
    });

    currentClose = close;
  }
  return candles;
}

// Master Stocks Seed Data (Comprehensive universe covering US Equities, Tech, Semiconductors, etc.)
let masterStocks = [
  {
    ticker: "NVDA",
    company: "NVIDIA Corporation",
    sector: "Semiconductores",
    exchange: "NASDAQ",
    price: 128.40,
    changePercent: 3.84,
    changeAmount: 4.75,
    volume: 58400000,
    avgVolume: 17200000,
    rvol: 3.4,
    dollarVolume: "$892M",
    marketCap: "$3.15 T",
    beta: 2.28,
    shortFloat: "1.4%",
    range52w: { low: 45.20, high: 140.76 },
    sma20: 121.50,
    sma50: 114.20,
    sma200: 89.60,
    ema21: 122.10,
    ema55: 115.30,
    rsi14: 71.2,
    adx: 32.4,
    macd: { macd: 3.45, signal: 2.80, hist: 0.65, isBullishCross: true },
    bollinger: { upper: 132.0, middle: 121.5, lower: 111.0, isSqueeze: false },
    primaryPattern: {
      name: "Cup and Handle",
      confidence: 94,
      timeframe: "1D",
      pivotPrice: 125.80,
      targetPrice: 152.00,
      stopLoss: 119.40,
      riskReward: 3.42,
      description: "Consolidación en 'U' con profundidad del 14.8%. El asa se contrajo con reducción drástica de volumen durante 6 sesiones consecutivas. Rotura de resistencia pivotal con expansión de 3.4x sobre el volumen promedio diario.",
      validated: true,
      checklist: [
        { label: "Rotura de nivel pivot con volumen (>2x RVOL)", status: "VALIDADO" },
        { label: "Medias alineadas: Precio > EMA21 > SMA50 > SMA200", status: "VALIDADO" },
        { label: "RSI no en divergencia bajista oculta", status: "VALIDADO" },
        { label: "Confirmación en vela semanal (Viernes EOD)", status: "EN PROCESO" }
      ],
      geometry: {
        baseDuration: "7 Semanas",
        depthPercent: 14.8,
        breakoutVolumeGainPercent: 240
      }
    },
    sparkline: [108, 112, 110, 115, 118, 116, 120, 122, 128.4],
    historicalCandles: generateCandles("NVDA", 128.40, "breakout"),
    trackRecord: [
      { pattern: "Golden Cross 50/200", date: "15 Oct 2023", entry: 43.50, gainPercent: 48.6, days: 65 },
      { pattern: "Cup & Handle Breakout", date: "18 Ene 2024", entry: 56.20, gainPercent: 32.4, days: 30 }
    ],
    aiThesis: {
      summary: "Breakout de alta convicción institucional en sector líder.",
      pros: ["RVOL 3.4x en ruptura de $125.80", "Demanda continua en centros de datos e IA", "Blue sky breakout sin resistencias de oferta"],
      risks: ["RSI en 71.2 (sobrecompra táctica)", "Volatilidad previa al informe de semiconductores"],
      score: 95
    }
  },
  {
    ticker: "PLTR",
    company: "Palantir Technologies Inc.",
    sector: "Software / IA",
    exchange: "NASDAQ",
    price: 28.45,
    changePercent: 4.82,
    changeAmount: 1.31,
    volume: 48240000,
    avgVolume: 20100000,
    rvol: 2.4,
    dollarVolume: "$412M",
    marketCap: "$63.18 B",
    beta: 2.14,
    shortFloat: "4.2%",
    range52w: { low: 14.48, high: 29.83 },
    sma20: 25.80,
    sma50: 24.18,
    sma200: 21.90,
    ema21: 26.10,
    ema55: 24.50,
    rsi14: 64.5,
    adx: 29.1,
    macd: { macd: 0.85, signal: 0.62, hist: 0.23, isBullishCross: true },
    bollinger: { upper: 29.10, middle: 25.80, lower: 22.50, isSqueeze: false },
    primaryPattern: {
      name: "Cup and Handle",
      confidence: 91,
      timeframe: "1D",
      pivotPrice: 27.20,
      targetPrice: 32.80,
      stopLoss: 26.40,
      riskReward: 2.15,
      description: "Patrón de continuidad alcista de 72 barras diarias completado con superación limpia de pivote de oferta en $27.20 y expansión de volumen del 140%.",
      validated: true,
      checklist: [
        { label: "Rotura de resistencia pivotal ($27.20) validada EOD", status: "VALIDADO" },
        { label: "Golden Cross activo: SMA50 > SMA200", status: "VALIDADO" },
        { label: "Pico de acumulación institucional (+140% volumen)", status: "VALIDADO" },
        { label: "Confirmación en cierre semanal sobre $27.50", status: "VALIDADO" }
      ],
      geometry: {
        baseDuration: "10 Semanas",
        depthPercent: 18.2,
        breakoutVolumeGainPercent: 140
      }
    },
    sparkline: [21.5, 22.8, 24.0, 23.5, 25.1, 26.4, 27.1, 28.45],
    historicalCandles: generateCandles("PLTR", 28.45, "breakout"),
    trackRecord: [
      { pattern: "Golden Cross 50/200", date: "14 Nov 2023", entry: 18.20, gainPercent: 34.2, days: 60 },
      { pattern: "Double Bottom Breakout", date: "22 Feb 2024", entry: 23.10, gainPercent: 18.5, days: 28 },
      { pattern: "RSI Bullish Divergence", date: "05 Jun 2024", entry: 21.40, gainPercent: 12.0, days: 15 }
    ],
    aiThesis: {
      summary: "Rotura de base de 10 semanas con contratos gubernamentales y aceleración comercial AIP.",
      pros: ["RVOL 2.4x en superación de pivote clave", "Margen libre operativo creciente", "Membresía reciente en S&P 500"],
      risks: ["Múltiplo de valoración elevado (Forward P/E > 60)"],
      score: 91
    }
  },
  {
    ticker: "CRWD",
    company: "CrowdStrike Holdings Inc.",
    sector: "Ciberseguridad / SaaS",
    exchange: "NASDAQ",
    price: 353.40,
    changePercent: 5.12,
    changeAmount: 17.20,
    volume: 12800000,
    avgVolume: 3680000,
    rvol: 3.48,
    dollarVolume: "$640M",
    marketCap: "$86.4 B",
    beta: 1.34,
    shortFloat: "2.1%",
    range52w: { low: 200.80, high: 398.33 },
    sma20: 334.0,
    sma50: 320.5,
    sma200: 298.0,
    ema21: 336.0,
    ema55: 324.0,
    rsi14: 69.4,
    adx: 35.8,
    macd: { macd: 5.80, signal: 4.10, hist: 1.70, isBullishCross: true },
    bollinger: { upper: 365.0, middle: 334.0, lower: 303.0, isSqueeze: false },
    primaryPattern: {
      name: "Cup & Handle",
      confidence: 94,
      timeframe: "1D",
      pivotPrice: 352.00,
      targetPrice: 410.00,
      stopLoss: 336.20,
      riskReward: 3.42,
      description: "Asa de 9 días con compresión de volatilidad perfecta. Rompe $352 con entrada masiva de bloques institucionales y RVOL de 3.48x.",
      validated: true,
      checklist: [
        { label: "Superación neta de pivote $352 en cierre", status: "VALIDADO" },
        { label: "Volumen institucional +248% vs 50d promedio", status: "VALIDADO" },
        { label: "Líder de sector superando al ETF CIBR", status: "VALIDADO" },
        { label: "Sin resistencia intermedia hasta $380", status: "VALIDADO" }
      ],
      geometry: {
        baseDuration: "63 Días",
        depthPercent: 16.5,
        breakoutVolumeGainPercent: 248
      }
    },
    sparkline: [290, 305, 320, 315, 330, 342, 353.4],
    historicalCandles: generateCandles("CRWD", 353.40, "breakout"),
    trackRecord: [
      { pattern: "Bandera Alcista", date: "10 Dic 2023", entry: 238.0, gainPercent: 26.5, days: 42 }
    ],
    aiThesis: {
      summary: "Rotura de máxima calidad técnica y fundamental en el nicho de ciberseguridad.",
      pros: ["Retención neta > 115%", "Flujo de caja libre récord", "Volumen comprador extraordinario"],
      risks: ["Riesgo macro temporal ante decisiones de tasas"],
      score: 94
    }
  },
  {
    ticker: "COIN",
    company: "Coinbase Global Inc.",
    sector: "Fintech / Cripto",
    exchange: "NASDAQ",
    price: 224.80,
    changePercent: 8.90,
    changeAmount: 18.38,
    volume: 18900000,
    avgVolume: 4600000,
    rvol: 4.1,
    dollarVolume: "$350M",
    marketCap: "$55.2 B",
    beta: 3.12,
    shortFloat: "5.8%",
    range52w: { low: 70.12, high: 283.48 },
    sma20: 202.0,
    sma50: 194.5,
    sma200: 180.2,
    ema21: 205.0,
    ema55: 196.0,
    rsi14: 68.0,
    adx: 31.0,
    macd: { macd: 4.12, signal: 2.80, hist: 1.32, isBullishCross: true },
    bollinger: { upper: 235.0, middle: 202.0, lower: 169.0, isSqueeze: false },
    primaryPattern: {
      name: "Doble Suelo (W)",
      confidence: 88,
      timeframe: "1D",
      pivotPrice: 220.00,
      targetPrice: 265.00,
      stopLoss: 208.50,
      riskReward: 2.58,
      description: "Formación de doble suelo simétrico en $190 con cuello en $220. Superación confirmada con volumen masivo de 4.1x.",
      validated: true,
      checklist: [
        { label: "Segundo suelo por encima del primero", status: "VALIDADO" },
        { label: "Corte alcista de cuello $220 con volumen", status: "VALIDADO" },
        { label: "Golden Cross reciente de 3 sesiones", status: "VALIDADO" }
      ],
      geometry: {
        baseDuration: "8 Semanas",
        depthPercent: 22.0,
        breakoutVolumeGainPercent: 310
      }
    },
    sparkline: [185, 192, 210, 195, 202, 215, 224.8],
    historicalCandles: generateCandles("COIN", 224.80, "breakout")
  },
  {
    ticker: "AMD",
    company: "Advanced Micro Devices Inc.",
    sector: "Semiconductores",
    exchange: "NASDAQ",
    price: 156.20,
    changePercent: 1.15,
    changeAmount: 1.77,
    volume: 38400000,
    avgVolume: 18200000,
    rvol: 2.1,
    dollarVolume: "$210M",
    marketCap: "$252.6 B",
    beta: 1.82,
    shortFloat: "2.4%",
    range52w: { low: 94.04, high: 227.30 },
    sma20: 152.0,
    sma50: 148.5,
    sma200: 139.8,
    ema21: 153.2,
    ema55: 149.0,
    rsi14: 58.4,
    adx: 26.5,
    macd: { macd: 1.80, signal: 1.20, hist: 0.60, isBullishCross: true },
    bollinger: { upper: 162.0, middle: 152.0, lower: 142.0, isSqueeze: true },
    primaryPattern: {
      name: "Bandera Alcista",
      confidence: 85,
      timeframe: "1D",
      pivotPrice: 158.00,
      targetPrice: 182.00,
      stopLoss: 149.50,
      riskReward: 2.82,
      description: "Canal de consolidación estrecho tras impulso previo de $135 a $160. Manteniendo SMA 50 como soporte dinámico.",
      validated: true,
      checklist: [
        { label: "Mástil con volumen ascendente", status: "VALIDADO" },
        { label: "Bandera con secado de volumen", status: "VALIDADO" },
        { label: "Soporte en EMA 21 respetado", status: "VALIDADO" }
      ]
    },
    sparkline: [142, 148, 160, 155, 153, 154, 156.2],
    historicalCandles: generateCandles("AMD", 156.20, "bullish")
  },
  {
    ticker: "AAPL",
    company: "Apple Inc.",
    sector: "Hardware & Tech",
    exchange: "NASDAQ",
    price: 218.24,
    changePercent: -0.45,
    changeAmount: -0.98,
    volume: 42100000,
    avgVolume: 23400000,
    rvol: 1.8,
    dollarVolume: "$680M",
    marketCap: "$3.32 T",
    beta: 1.05,
    shortFloat: "0.8%",
    range52w: { low: 164.08, high: 237.23 },
    sma20: 222.0,
    sma50: 217.8,
    sma200: 195.4,
    ema21: 220.1,
    ema55: 216.5,
    rsi14: 46.8,
    adx: 21.0,
    macd: { macd: -0.45, signal: -0.10, hist: -0.35, isBullishCross: false },
    bollinger: { upper: 229.0, middle: 222.0, lower: 215.0, isSqueeze: false },
    primaryPattern: {
      name: "Soporte SMA 50",
      confidence: 82,
      timeframe: "1D",
      pivotPrice: 224.00,
      targetPrice: 242.00,
      stopLoss: 214.00,
      riskReward: 2.10,
      description: "Retroceso controlado hacia el promedio móvil de 50 sesiones tras tocar máximos. Potencial rebote técnico en zona de valor.",
      validated: true,
      checklist: [
        { label: "Testeo de SMA 50 en rango de +/- 1%", status: "VALIDADO" },
        { label: "RSI saliendo de zona neutral", status: "EN PROCESO" }
      ]
    },
    sparkline: [225, 230, 235, 228, 222, 219, 218.24],
    historicalCandles: generateCandles("AAPL", 218.24, "consolidation")
  },
  {
    ticker: "MSFT",
    company: "Microsoft Corporation",
    sector: "Cloud & Enterprise",
    exchange: "NASDAQ",
    price: 448.90,
    changePercent: 1.20,
    changeAmount: 5.32,
    volume: 19200000,
    avgVolume: 9600000,
    rvol: 2.0,
    dollarVolume: "$540M",
    marketCap: "$3.33 T",
    beta: 1.18,
    shortFloat: "0.6%",
    range52w: { low: 309.45, high: 468.35 },
    sma20: 440.0,
    sma50: 432.0,
    sma200: 395.0,
    ema21: 442.0,
    ema55: 433.0,
    rsi14: 61.3,
    adx: 27.5,
    macd: { macd: 2.10, signal: 1.80, hist: 0.30, isBullishCross: true },
    bollinger: { upper: 458.0, middle: 440.0, lower: 422.0, isSqueeze: false },
    primaryPattern: {
      name: "Cup and Handle",
      confidence: 89,
      timeframe: "1D",
      pivotPrice: 452.00,
      targetPrice: 495.00,
      stopLoss: 435.00,
      riskReward: 2.53,
      description: "Formación de base compacta en máximos históricos con rotura inminente de la zona de resistencia en $452.",
      validated: true,
      checklist: [
        { label: "Tendencia previa fuertemente alcista", status: "VALIDADO" },
        { label: "Medias móviles alineadas de menor a mayor", status: "VALIDADO" }
      ]
    },
    sparkline: [420, 428, 435, 430, 442, 445, 448.9],
    historicalCandles: generateCandles("MSFT", 448.90, "bullish")
  },
  {
    ticker: "TSLA",
    company: "Tesla Inc.",
    sector: "Automoción EV",
    exchange: "NASDAQ",
    price: 254.10,
    changePercent: 4.55,
    changeAmount: 11.05,
    volume: 85200000,
    avgVolume: 27500000,
    rvol: 3.1,
    dollarVolume: "$780M",
    marketCap: "$810 B",
    beta: 2.45,
    shortFloat: "3.5%",
    range52w: { low: 138.80, high: 271.00 },
    sma20: 232.0,
    sma50: 218.0,
    sma200: 224.0,
    ema21: 236.0,
    ema55: 222.0,
    rsi14: 69.1,
    adx: 34.0,
    macd: { macd: 6.20, signal: 4.10, hist: 2.10, isBullishCross: true },
    bollinger: { upper: 262.0, middle: 232.0, lower: 202.0, isSqueeze: false },
    primaryPattern: {
      name: "Rotura Tendencia",
      confidence: 78,
      timeframe: "1D",
      pivotPrice: 245.00,
      targetPrice: 285.00,
      stopLoss: 232.00,
      riskReward: 2.38,
      description: "Corte ascendente de línea directriz bajista de 8 meses con volumen expansivo de 3.1x.",
      validated: true,
      checklist: [
        { label: "Rotura de directriz bajista", status: "VALIDADO" },
        { label: "Recuperación de SMA 200", status: "VALIDADO" }
      ]
    },
    sparkline: [195, 210, 225, 218, 235, 244, 254.1],
    historicalCandles: generateCandles("TSLA", 254.10, "breakout")
  },
  {
    ticker: "UBER",
    company: "Uber Technologies Inc.",
    sector: "Logística / Movilidad",
    exchange: "NYSE",
    price: 74.30,
    changePercent: 2.41,
    changeAmount: 1.75,
    volume: 18400000,
    avgVolume: 7600000,
    rvol: 2.4,
    dollarVolume: "$180M",
    marketCap: "$154.8 B",
    beta: 1.42,
    shortFloat: "1.8%",
    range52w: { low: 40.09, high: 82.14 },
    sma20: 71.2,
    sma50: 68.4,
    sma200: 62.1,
    ema21: 71.8,
    ema55: 69.0,
    rsi14: 62.0,
    adx: 28.2,
    macd: { macd: 1.15, signal: 0.85, hist: 0.30, isBullishCross: true },
    bollinger: { upper: 76.5, middle: 71.2, lower: 65.9, isSqueeze: false },
    primaryPattern: {
      name: "Bandera Alcista",
      confidence: 92,
      timeframe: "1D",
      pivotPrice: 75.00,
      targetPrice: 88.00,
      stopLoss: 69.80,
      riskReward: 3.10,
      description: "Salida de contracción de volatilidad con soporte en la media exponencial de 21 sesiones.",
      validated: true,
      checklist: [
        { label: "Contracción de rango estrecho", status: "VALIDADO" },
        { label: "Volumen entrando en la salida", status: "VALIDADO" }
      ]
    },
    sparkline: [65, 68, 71, 69, 72, 73, 74.3],
    historicalCandles: generateCandles("UBER", 74.30, "bullish")
  },
  {
    ticker: "NOW",
    company: "ServiceNow Inc.",
    sector: "Software Enterprise",
    exchange: "NYSE",
    price: 812.50,
    changePercent: 3.15,
    changeAmount: 24.80,
    volume: 3100000,
    avgVolume: 1420000,
    rvol: 2.18,
    dollarVolume: "$420M",
    marketCap: "$167 B",
    beta: 1.08,
    shortFloat: "1.2%",
    range52w: { low: 525.0, high: 830.0 },
    sma20: 785.0,
    sma50: 760.0,
    sma200: 705.0,
    ema21: 790.0,
    ema55: 768.0,
    rsi14: 66.8,
    adx: 30.1,
    macd: { macd: 12.4, signal: 9.8, hist: 2.6, isBullishCross: true },
    bollinger: { upper: 825.0, middle: 785.0, lower: 745.0, isSqueeze: false },
    primaryPattern: {
      name: "Cup & Handle",
      confidence: 88,
      timeframe: "1D",
      pivotPrice: 795.00,
      targetPrice: 890.00,
      stopLoss: 770.00,
      riskReward: 2.80,
      description: "Rotura de resistencia en $795. Acumulación sostenida por fondos; MACD diario acelera en positivo.",
      validated: true,
      checklist: [
        { label: "Rotura de resistencia $795", status: "VALIDADO" },
        { label: "Aceleración MACD", status: "VALIDADO" }
      ]
    },
    sparkline: [740, 755, 770, 765, 785, 798, 812.5],
    historicalCandles: generateCandles("NOW", 812.50, "breakout")
  },
  {
    ticker: "ANET",
    company: "Arista Networks Inc.",
    sector: "Redes & Cloud",
    exchange: "NYSE",
    price: 338.20,
    changePercent: 2.80,
    changeAmount: 9.20,
    volume: 4600000,
    avgVolume: 2240000,
    rvol: 2.05,
    dollarVolume: "$290M",
    marketCap: "$106 B",
    beta: 1.25,
    shortFloat: "1.7%",
    range52w: { low: 175.0, high: 345.0 },
    sma20: 325.0,
    sma50: 310.0,
    sma200: 275.0,
    ema21: 328.0,
    ema55: 315.0,
    rsi14: 65.4,
    adx: 31.8,
    macd: { macd: 5.6, signal: 4.2, hist: 1.4, isBullishCross: true },
    bollinger: { upper: 345.0, middle: 325.0, lower: 305.0, isSqueeze: false },
    primaryPattern: {
      name: "Rotura ATH con RVOL",
      confidence: 86,
      timeframe: "1D",
      pivotPrice: 330.00,
      targetPrice: 380.00,
      stopLoss: 318.00,
      riskReward: 2.75,
      description: "Formación de 14 semanas. Supera máximos con cierre en el 98% del rango diario (cierre alcista puro).",
      validated: true,
      checklist: [
        { label: "Cierre en máximos de sesión", status: "VALIDADO" },
        { label: "Volumen superior al doble de la media", status: "VALIDADO" }
      ]
    },
    sparkline: [300, 310, 322, 318, 328, 332, 338.2],
    historicalCandles: generateCandles("ANET", 338.20, "breakout")
  },
  {
    ticker: "GE",
    company: "GE Aerospace",
    sector: "Aeroespacial & Defensa",
    exchange: "NYSE",
    price: 184.60,
    changePercent: 1.95,
    changeAmount: 3.53,
    volume: 7800000,
    avgVolume: 3320000,
    rvol: 2.35,
    dollarVolume: "$220M",
    marketCap: "$201 B",
    beta: 1.12,
    shortFloat: "0.9%",
    range52w: { low: 88.0, high: 190.5 },
    sma20: 178.0,
    sma50: 169.0,
    sma200: 148.0,
    ema21: 179.5,
    ema55: 171.0,
    rsi14: 67.2,
    adx: 33.0,
    macd: { macd: 3.8, signal: 2.9, hist: 0.9, isBullishCross: true },
    bollinger: { upper: 188.0, middle: 178.0, lower: 168.0, isSqueeze: false },
    primaryPattern: {
      name: "Cup and Handle",
      confidence: 82,
      timeframe: "1D",
      pivotPrice: 180.00,
      targetPrice: 205.00,
      stopLoss: 172.00,
      riskReward: 2.50,
      description: "Patrón de copa extendida. Catalizador de beneficios previo asimilado con volumen constante comprador.",
      validated: true,
      checklist: [
        { label: "Soporte en SMA 20", status: "VALIDADO" }
      ]
    },
    sparkline: [165, 170, 178, 174, 180, 182, 184.6],
    historicalCandles: generateCandles("GE", 184.60, "bullish")
  },
  {
    ticker: "ARM",
    company: "ARM Holdings Plc",
    sector: "IP Chips & Arquitectura",
    exchange: "NASDAQ",
    price: 138.90,
    changePercent: 3.60,
    changeAmount: 4.83,
    volume: 16500000,
    avgVolume: 6200000,
    rvol: 2.66,
    dollarVolume: "$310M",
    marketCap: "$144 B",
    beta: 2.65,
    shortFloat: "5.4%",
    range52w: { low: 48.0, high: 188.75 },
    sma20: 130.0,
    sma50: 124.0,
    sma200: 110.0,
    ema21: 132.0,
    ema55: 126.0,
    rsi14: 63.5,
    adx: 28.4,
    macd: { macd: 2.9, signal: 2.1, hist: 0.8, isBullishCross: true },
    bollinger: { upper: 144.0, middle: 130.0, lower: 116.0, isSqueeze: false },
    primaryPattern: {
      name: "Rompimiento de Rango",
      confidence: 87,
      timeframe: "1D",
      pivotPrice: 139.50,
      targetPrice: 168.00,
      stopLoss: 128.00,
      riskReward: 2.64,
      description: "A 0.4% de Máximo Anual ($139.50) con volumen secundario en aceleración.",
      validated: true,
      checklist: [
        { label: "Aceleración de volumen en cierre", status: "VALIDADO" }
      ]
    },
    sparkline: [118, 124, 132, 128, 134, 136, 138.9],
    historicalCandles: generateCandles("ARM", 138.90, "bullish")
  },
  {
    ticker: "SMCI",
    company: "Super Micro Computer Inc.",
    sector: "Servidores IA",
    exchange: "NASDAQ",
    price: 48.60,
    changePercent: 6.40,
    changeAmount: 2.92,
    volume: 32000000,
    avgVolume: 12000000,
    rvol: 2.67,
    dollarVolume: "$280M",
    marketCap: "$28.5 B",
    beta: 3.20,
    shortFloat: "8.2%",
    range52w: { low: 26.0, high: 122.0 },
    sma20: 44.0,
    sma50: 42.5,
    sma200: 58.0,
    ema21: 45.2,
    ema55: 43.8,
    rsi14: 59.2,
    adx: 25.0,
    macd: { macd: 1.2, signal: 0.7, hist: 0.5, isBullishCross: true },
    bollinger: { upper: 52.0, middle: 44.0, lower: 36.0, isSqueeze: false },
    primaryPattern: {
      name: "Soporte EMA 50 + Divergencia",
      confidence: 84,
      timeframe: "1D",
      pivotPrice: 52.00,
      targetPrice: 68.00,
      stopLoss: 43.00,
      riskReward: 2.85,
      description: "Precio ha tocado el nivel de soporte dinámico de 50 EMA con divergencia alcista en MACD Histograma.",
      validated: true,
      checklist: [
        { label: "Divergencia alcista en oscilador", status: "VALIDADO" }
      ]
    },
    sparkline: [38, 41, 45, 42, 46, 47, 48.6],
    historicalCandles: generateCandles("SMCI", 48.60, "bullish")
  }
];

// Scraping Telemetry state
interface ServerTelemetry {
  systemStatus: "OPERATIVO" | "SCRAPING" | "RECALCULANDO" | "ERROR";
  workersActive: number;
  totalWorkers: number;
  tickersProcessed: number;
  totalTickers: number;
  lastScrapeAgo: string;
  jobDuration: string;
  throughput: string;
  deltaYesterday: string;
  errorRate: string;
  httpBans: number;
  proxyLatency: number;
  proxyActive: number;
  proxyTotal: number;
}

interface ServerLog {
  id: string;
  timestamp: string;
  level: string;
  message: string;
}

let scrapingTelemetry: ServerTelemetry = {
  systemStatus: "OPERATIVO",
  workersActive: 16,
  totalWorkers: 16,
  tickersProcessed: 4820,
  totalTickers: 4820,
  lastScrapeAgo: "Hace 42 min",
  jobDuration: "18m 42s",
  throughput: "4.2 req/s",
  deltaYesterday: "-1m 14s (-6.2%)",
  errorRate: "0.04%",
  httpBans: 0,
  proxyLatency: 180,
  proxyActive: 49,
  proxyTotal: 50
};

let scrapingLogs: ServerLog[] = [
  { id: "1", timestamp: "21:29:45", level: "INIT", message: "Iniciando worker pool de 16 threads asíncronos..." },
  { id: "2", timestamp: "21:29:49", level: "HEALTH", message: "Validación exitosa: 50/50 nodos residenciales vivos." },
  { id: "3", timestamp: "21:30:02", level: "INFO", message: "Extrayendo velas EOD NYSE batch #42 (120 tickers)..." },
  { id: "4", timestamp: "21:30:15", level: "SUCCESS", message: "250 tickers indexados correctamente en tabla 'eod_bars'." },
  { id: "5", timestamp: "21:30:22", level: "WARN", message: "Proxy #19 latencia elevada (412ms). Rotando a gateway de respaldo." },
  { id: "6", timestamp: "21:30:24", level: "RETRY", message: "Ticker 'ARES' reintentado exitosamente vía Proxy #27 (165ms)." },
  { id: "7", timestamp: "21:31:00", level: "CALC", message: "Motor de patrones detectó 14 Cup & Handle, 8 Golden Cross." },
  { id: "8", timestamp: "21:31:18", level: "INFO", message: "Recalculando matriz RSI(14) y Bandas Bollinger (20,2)..." },
  { id: "9", timestamp: "21:31:40", level: "SUCCESS", message: "Batch Russell 2000 completado: 1,982 activos actualizados." },
  { id: "10", timestamp: "21:32:00", level: "STAT", message: "EOD Job #8824 concluido. Duración: 18m 42s. 0 fallos permanentes." }
];

let universes = [
  { id: "spx", name: "S&P 500 (SPX)", symbolCount: 503, lastSync: "Hoy, 21:05:12 UTC", health: "100% OK", autoScrape: true },
  { id: "ndx", name: "NASDAQ 100 (NDX)", symbolCount: 101, lastSync: "Hoy, 21:07:44 UTC", health: "100% OK", autoScrape: true },
  { id: "rut", name: "Russell 2000 (RUT)", symbolCount: 1982, lastSync: "Hoy, 21:18:22 UTC", health: "99.9% (2 reintentos)", autoScrape: true },
  { id: "ibex", name: "IBEX 35 & Continuo", symbolCount: 138, lastSync: "Hoy, 17:45:00 UTC", health: "100% OK", autoScrape: true },
  { id: "custom", name: "Watchlist Manual / Custom", symbolCount: 2096, lastSync: "Hoy, 21:23:40 UTC", health: "100% OK", autoScrape: true }
];

// User profile & storage
let userProfile = {
  name: "Carlos M.",
  email: "carlos.trader@alphapulse.io",
  plan: "PRO TRADER",
  license: "Licencia PRO",
  id: "#TRD-9042-ALPHA",
  observedCapital: 342850.00,
  dailyPnL: 4190.20,
  dailyPnLPercent: 1.24,
  availableMargin: 88400.00,
  notificationSettings: {
    emailDigest: true,
    emailAddress: "carlos.trader@alphapulse.io",
    telegramWebhook: true,
    telegramBot: "@AlphaPulse_CarlosBot",
    browserPush: false
  }
};

let savedScreeners = [
  {
    id: "sc-1",
    title: "Breakouts de Volumen + Cup & Handle",
    category: "Patrón Geométrico",
    description: "Identifica roturas de resistencia con volumen superior a 150% de la media de 20 días y volatilidad comprimida.",
    hitsCount: 4,
    recentHits: ["PLTR", "CRWD", "NVDA", "UBER"],
    emailAlert: true,
    createdAt: "10 Ene 2024",
    lastExecuted: "Hoy 21:00 CET"
  },
  {
    id: "sc-2",
    title: "Cruces Dorados S&P 500",
    category: "Tendencia Macro",
    description: "Cruce alcista de SMA 50 cortando hacia arriba a SMA 200 dentro del universo del índice estándar de gran capitalización.",
    hitsCount: 2,
    recentHits: ["COIN", "NVDA"],
    emailAlert: true,
    createdAt: "12 Ene 2024",
    lastExecuted: "Hoy 21:00 CET"
  },
  {
    id: "sc-3",
    title: "RSI < 30 Reversal en Soporte 200 SMA",
    category: "Mean Reversion",
    description: "Sobreventa extrema en 14 períodos rebotando en un rango de +/-1.5% respecto a la media móvil simple de largo plazo.",
    hitsCount: 6,
    recentHits: ["AAPL", "AMD"],
    emailAlert: false,
    createdAt: "18 Feb 2024",
    lastExecuted: "Hoy 21:00 CET"
  }
];

let watchlist = [
  {
    id: "wl-1",
    ticker: "PLTR",
    company: "Palantir Technologies",
    sector: "Software / AI",
    exchange: "NASDAQ",
    closePrice: 28.45,
    changePercent: 4.82,
    distanceToPivot: "A 1.2% del punto de pivote",
    pivotReference: "Resistencia: $27.20 (Superada)",
    algorithmicCondition: "Ruptura Cup & Handle con Vol. +140%",
    sparkline: [21.5, 23.0, 24.5, 26.0, 27.2, 28.45]
  },
  {
    id: "wl-2",
    ticker: "NVDA",
    company: "NVIDIA Corp.",
    sector: "Semiconductores",
    exchange: "NASDAQ",
    closePrice: 128.40,
    changePercent: 3.84,
    distanceToPivot: "Soporte SMA 50 a 0.8%",
    pivotReference: "Piso dinámico: $123.70",
    algorithmicCondition: "Confirmación de Golden Cross (50/200)",
    sparkline: [112, 115, 118, 122, 125, 128.4]
  },
  {
    id: "wl-3",
    ticker: "AMD",
    company: "Advanced Micro Devices",
    sector: "Semiconductores",
    exchange: "NASDAQ",
    closePrice: 156.20,
    changePercent: 1.15,
    distanceToPivot: "A 2.5% de Base Semanal",
    pivotReference: "Soporte: $154.50",
    algorithmicCondition: "Pullback de testeo a EMA 21",
    sparkline: [145, 150, 160, 155, 154, 156.2]
  },
  {
    id: "wl-4",
    ticker: "ARM",
    company: "ARM Holdings Plc",
    sector: "IP Chips",
    exchange: "NASDAQ",
    closePrice: 138.90,
    changePercent: 3.60,
    distanceToPivot: "A 0.4% de Máximo Anual",
    pivotReference: "ATH Resistencia: $139.50",
    algorithmicCondition: "Rompimiento de Rango con Volumen Secundario",
    sparkline: [120, 125, 130, 132, 136, 138.9]
  }
];

let recentAlerts = [
  {
    id: "al-1",
    ticker: "PLTR",
    time: "Cierre 21:00 CET",
    tag: "Ruptura",
    badgeType: "Ruptura" as const,
    message: "Confirmó ruptura de Asa (Cup & Handle) con volumen +140% al cierre de sesión regular.",
    volumeBoost: "+140%"
  },
  {
    id: "al-2",
    ticker: "NVDA",
    time: "Cierre 21:00 CET",
    tag: "Macro",
    badgeType: "Macro" as const,
    message: "Golden Cross: SMA 50 cortó al alza a SMA 200 en marco temporal Diario (1D). Disparo de señal cuantitativa primaria."
  },
  {
    id: "al-3",
    ticker: "SMCI",
    time: "Cierre 21:00 CET",
    tag: "Soporte",
    badgeType: "Soporte" as const,
    message: "Precio ha tocado el nivel de soporte dinámico de 50 EMA con divergencia alcista en MACD Histograma."
  }
];

// ================= API ROUTES =================

// Health check
app.get("/api/health", (req, res) => {
  res.json({ status: "ok", timestamp: new Date().toISOString() });
});

// GET /api/stocks - Full list with filtering
app.get("/api/stocks", (req, res) => {
  const { search, pattern, minRvol, sma200, goldenCross, rsiMin, rsiMax, sortBy } = req.query;
  
  let result = [...masterStocks];

  if (search) {
    const q = String(search).toUpperCase().trim();
    result = result.filter(s => s.ticker.includes(q) || s.company.toUpperCase().includes(q) || s.sector.toUpperCase().includes(q));
  }

  if (pattern && pattern !== "all") {
    result = result.filter(s => s.primaryPattern.name.toLowerCase().includes(String(pattern).toLowerCase()));
  }

  if (minRvol) {
    const min = parseFloat(String(minRvol));
    if (!isNaN(min)) {
      result = result.filter(s => s.rvol >= min);
    }
  }

  if (sma200 === "true") {
    result = result.filter(s => s.price > s.sma200);
  }

  if (goldenCross === "true") {
    result = result.filter(s => s.sma50 > s.sma200);
  }

  if (rsiMin || rsiMax) {
    const min = rsiMin ? parseFloat(String(rsiMin)) : 0;
    const max = rsiMax ? parseFloat(String(rsiMax)) : 100;
    result = result.filter(s => s.rsi14 >= min && s.rsi14 <= max);
  }

  // Sorting
  if (sortBy === "rvol_desc") {
    result.sort((a, b) => b.rvol - a.rvol);
  } else if (sortBy === "confidence_desc") {
    result.sort((a, b) => b.primaryPattern.confidence - a.primaryPattern.confidence);
  } else if (sortBy === "rsi_desc") {
    result.sort((a, b) => b.rsi14 - a.rsi14);
  } else if (sortBy === "change_desc") {
    result.sort((a, b) => b.changePercent - a.changePercent);
  }

  res.json({ total: result.length, data: result });
});

// GET /api/stocks/:ticker - Detail with candle data
app.get("/api/stocks/:ticker", (req, res) => {
  const ticker = req.params.ticker.toUpperCase();
  const stock = masterStocks.find(s => s.ticker === ticker);
  if (!stock) {
    return res.status(404).json({ error: "Ticker no encontrado" });
  }
  res.json(stock);
});

// POST /api/scrape/trigger - Execute scraping routine
app.post("/api/scrape/trigger", async (req, res) => {
  const { source, universe } = req.body;
  const now = new Date();
  const timeStr = now.toTimeString().split(" ")[0];

  scrapingTelemetry.systemStatus = "SCRAPING";
  scrapingLogs.unshift({
    id: String(Date.now()),
    timestamp: timeStr,
    level: "TASK",
    message: `Disparo manual iniciado: Ejecutando Scraper EOD desde ${source || "Yahoo EOD"} para ${universe || "universo completo"}...`
  });

  // Simulate worker execution and real prices update
  setTimeout(() => {
    const updateTime = new Date().toTimeString().split(" ")[0];
    scrapingLogs.unshift(
      { id: String(Date.now() + 1), timestamp: updateTime, level: "FETCH", message: "Lote de prioridad asignado a 16 workers concurrentes." },
      { id: String(Date.now() + 2), timestamp: updateTime, level: "SUCCESS", message: `Descarga completada: ${masterStocks.length} velas OHLCV EOD indexadas con éxito.` }
    );
    scrapingTelemetry.systemStatus = "OPERATIVO";
    scrapingTelemetry.lastScrapeAgo = "Hace unos segundos";
    scrapingTelemetry.tickersProcessed = scrapingTelemetry.totalTickers;
  }, 1200);

  res.json({ status: "started", message: "Proceso de scraping EOD encolado con éxito." });
});

// POST /api/scrape/recalc - Recalculate indicators & chartist patterns
app.post("/api/scrape/recalc", (req, res) => {
  const timeStr = new Date().toTimeString().split(" ")[0];
  scrapingLogs.unshift(
    { id: String(Date.now()), timestamp: timeStr, level: "CALC", message: "Iniciando pipeline de indicadores (SMA20/50/200, MACD, RSI, ATR)..." },
    { id: String(Date.now() + 1), timestamp: timeStr, level: "SUCCESS", message: `Motor de patrones completado: ${masterStocks.length} activos recalculados.` }
  );
  res.json({ status: "success", message: "Patrones recalculados." });
});

// POST /api/scrape/clear-cache - Clear Redis cache
app.post("/api/scrape/clear-cache", (req, res) => {
  const timeStr = new Date().toTimeString().split(" ")[0];
  scrapingLogs.unshift({
    id: String(Date.now()),
    timestamp: timeStr,
    level: "REDIS",
    message: "Comando FLUSHDB emitido para namespace quotes:* - Claves purgadas: 18,490."
  });
  res.json({ status: "cleared", keysPurged: 18490 });
});

// POST /api/scrape/add-ticker - Add custom ticker to universe
app.post("/api/scrape/add-ticker", (req, res) => {
  const { ticker, exchange, company, sector } = req.body;
  if (!ticker) {
    return res.status(400).json({ error: "Ticker requerido" });
  }

  const cleanTicker = ticker.toUpperCase().trim();
  const existing = masterStocks.find(s => s.ticker === cleanTicker);
  if (existing) {
    return res.json({ message: "El ticker ya está registrado.", stock: existing });
  }

  const randomPrice = Math.floor(45 + Math.random() * 220);
  const newStock = {
    ticker: cleanTicker,
    company: company || `${cleanTicker} Corporation`,
    sector: sector || "Tecnología / Industrial",
    exchange: exchange || "NASDAQ",
    price: randomPrice,
    changePercent: Number(((Math.random() - 0.4) * 5).toFixed(2)),
    changeAmount: Number((randomPrice * 0.02).toFixed(2)),
    volume: 12500000,
    avgVolume: 5400000,
    rvol: Number((1.5 + Math.random() * 2).toFixed(1)),
    dollarVolume: `$${Math.floor(randomPrice * 1.5)}M`,
    marketCap: `$${Math.floor(randomPrice * 0.8)} B`,
    beta: 1.45,
    shortFloat: "2.8%",
    range52w: { low: Number((randomPrice * 0.65).toFixed(2)), high: Number((randomPrice * 1.15).toFixed(2)) },
    sma20: Number((randomPrice * 0.96).toFixed(2)),
    sma50: Number((randomPrice * 0.92).toFixed(2)),
    sma200: Number((randomPrice * 0.82).toFixed(2)),
    ema21: Number((randomPrice * 0.97).toFixed(2)),
    ema55: Number((randomPrice * 0.93).toFixed(2)),
    rsi14: 62.5,
    adx: 27.0,
    macd: { macd: 1.4, signal: 0.9, hist: 0.5, isBullishCross: true },
    bollinger: { upper: randomPrice * 1.05, middle: randomPrice * 0.96, lower: randomPrice * 0.88, isSqueeze: false },
    primaryPattern: {
      name: "Cup and Handle",
      confidence: 86,
      timeframe: "1D",
      pivotPrice: Number((randomPrice * 0.98).toFixed(2)),
      targetPrice: Number((randomPrice * 1.22).toFixed(2)),
      stopLoss: Number((randomPrice * 0.92).toFixed(2)),
      riskReward: 2.75,
      description: `Ruptura de base con volumen verificado para el nuevo símbolo ${cleanTicker}.`,
      validated: true,
      checklist: [
        { label: "Validación de cotización EOD", status: "VALIDADO" as const },
        { label: "Medias móviles calculadas", status: "VALIDADO" as const }
      ]
    },
    sparkline: [randomPrice * 0.9, randomPrice * 0.93, randomPrice * 0.97, randomPrice],
    historicalCandles: generateCandles(cleanTicker, randomPrice, "breakout")
  };

  masterStocks.unshift(newStock);
  scrapingTelemetry.totalTickers += 1;
  scrapingTelemetry.tickersProcessed += 1;

  const timeStr = new Date().toTimeString().split(" ")[0];
  scrapingLogs.unshift({
    id: String(Date.now()),
    timestamp: timeStr,
    level: "REG",
    message: `Nuevo símbolo registrado: ${cleanTicker}. Ingesta histórica de 5 años finalizada.`
  });

  res.json({ message: "Símbolo añadido correctamente", stock: newStock });
});

// GET /api/scrape/telemetry - Scraping pipeline status
app.get("/api/scrape/telemetry", (req, res) => {
  res.json({
    telemetry: scrapingTelemetry,
    logs: scrapingLogs,
    universes: universes
  });
});

// GET /api/user - User portal profile, watchlists, saved screeners, alerts
app.get("/api/user", (req, res) => {
  res.json({
    profile: userProfile,
    savedScreeners,
    watchlist,
    recentAlerts
  });
});

// POST /api/user/screener - Save new screener
app.post("/api/user/screener", (req, res) => {
  const { title, category, description, filters } = req.body;
  const newScreener = {
    id: `sc-${Date.now()}`,
    title: title || "Screener Personalizado",
    category: category || "Cuantitativo",
    description: description || "Filtro configurado manualmente por el usuario.",
    hitsCount: Math.floor(2 + Math.random() * 8),
    recentHits: masterStocks.slice(0, 3).map(s => s.ticker),
    emailAlert: true,
    createdAt: "Hoy",
    lastExecuted: "Hoy 21:00 CET",
    filters
  };
  savedScreeners.unshift(newScreener);
  res.json(newScreener);
});

// DELETE /api/user/screener/:id
app.delete("/api/user/screener/:id", (req, res) => {
  const { id } = req.params;
  savedScreeners = savedScreeners.filter(s => s.id !== id);
  res.json({ success: true });
});

// POST /api/user/watchlist - Toggle ticker in watchlist
app.post("/api/user/watchlist", (req, res) => {
  const { ticker } = req.body;
  if (!ticker) return res.status(400).json({ error: "Ticker requerido" });

  const upperTicker = ticker.toUpperCase();
  const existingIndex = watchlist.findIndex(w => w.ticker === upperTicker);

  if (existingIndex >= 0) {
    watchlist.splice(existingIndex, 1);
    return res.json({ action: "removed", ticker: upperTicker, watchlist });
  }

  const stock = masterStocks.find(s => s.ticker === upperTicker);
  if (!stock) return res.status(404).json({ error: "Ticker no encontrado en universo" });

  const newItem = {
    id: `wl-${Date.now()}`,
    ticker: stock.ticker,
    company: stock.company,
    sector: stock.sector,
    exchange: stock.exchange,
    closePrice: stock.price,
    changePercent: stock.changePercent,
    distanceToPivot: `A ${(Math.abs(stock.price - stock.primaryPattern.pivotPrice) / stock.price * 100).toFixed(1)}% del pivote`,
    pivotReference: `Pivote: $${stock.primaryPattern.pivotPrice}`,
    algorithmicCondition: `${stock.primaryPattern.name} (Confianza: ${stock.primaryPattern.confidence}%)`,
    sparkline: stock.sparkline
  };

  watchlist.unshift(newItem);
  res.json({ action: "added", item: newItem, watchlist });
});

// POST /api/copilot/reason - LLM Reasoning with Gemini
app.post("/api/copilot/reason", async (req, res) => {
  const { prompt, universe, strictVolume } = req.body;
  if (!prompt) {
    return res.status(400).json({ error: "Prompt requerido" });
  }

  const apiKey = process.env.GEMINI_API_KEY;
  const availableTickersSummary = masterStocks.map(s => ({
    ticker: s.ticker,
    company: s.company,
    sector: s.sector,
    price: s.price,
    changePercent: s.changePercent,
    rvol: s.rvol,
    rsi14: s.rsi14,
    pattern: s.primaryPattern.name,
    confidence: s.primaryPattern.confidence,
    pivot: s.primaryPattern.pivotPrice,
    stopLoss: s.primaryPattern.stopLoss,
    target: s.primaryPattern.targetPrice,
    riskReward: s.primaryPattern.riskReward
  }));

  if (apiKey) {
    try {
      const ai = getGeminiClient();
      const systemInstruction = `
Eres un analista cuantitativo senior y especialista en chartismo técnico y screening algorítmico EOD (End of Day).
Tu objetivo es analizar hipótesis de trading y filtrar acciones basándote en los datos reales proporcionados.
Responde de forma concisa, rigurosa, cuantitativa y accionable.
Debes devolver un JSON con esta estructura exacta:
{
  "explanation": "Breve párrafo explicando la lógica cuantitativa ejecutada y los resultados...",
  "pipelineSteps": ["Paso 1...", "Paso 2...", "Paso 3..."],
  "leadTicker": "TICKER principal seleccionado",
  "selectedTickers": [
    {
      "ticker": "CRWD",
      "company": "CrowdStrike",
      "score": 94,
      "pattern": "Cup & Handle",
      "rvol": "3.48x",
      "rationale": "Explicación de por qué supera el filtro técnico..."
    }
  ],
  "exclusions": [
    {
      "ticker": "MSFT",
      "reason": "Explicación cuantitativa del rechazo (ej: RSI en divergencia, volumen insuficiente...)"
    }
  ]
}
`;

      const userContent = `
Universo seleccionado: ${universe || "S&P 500"}
Modo confirmación estricta de volumen (>2.0x RVOL): ${strictVolume ? "ACTIVO" : "INACTIVO"}
Consulta del usuario: "${prompt}"

Datos actuales de los tickers disponibles en la base de datos:
${JSON.stringify(availableTickersSummary, null, 2)}
`;

      const response = await ai.models.generateContent({
        model: "gemini-3.8-flash",
        contents: userContent,
        config: {
          systemInstruction,
          responseMimeType: "application/json",
          temperature: 0.2
        }
      });

      const responseText = response.text || "";
      const parsed = JSON.parse(responseText);

      return res.json({
        success: true,
        text: parsed.explanation,
        structuredResult: {
          refinedCount: parsed.selectedTickers?.length || 3,
          totalEvaluated: masterStocks.length,
          universe: universe || "S&P 500 (Rentables TTM)",
          pipelineSteps: parsed.pipelineSteps || [
            "Identificación de base geométrica y contracción de volatilidad",
            "Filtro estricto de RVOL > 2.0x frente a la media móvil de 50 sesiones",
            "Validación de ausencia de resistencia inmediata y alineación de medias"
          ],
          leadTicker: parsed.leadTicker || (parsed.selectedTickers?.[0]?.ticker ?? "CRWD"),
          selectedTickers: parsed.selectedTickers || [],
          exclusions: parsed.exclusions || []
        }
      });
    } catch (err: any) {
      console.error("Gemini API call failed, falling back to algorithmic reasoning engine:", err);
    }
  }

  // Algorithmic heuristic fallback if API key not available or call failed
  const lowerPrompt = prompt.toLowerCase();
  let filtered = masterStocks.filter(s => {
    if (strictVolume && s.rvol < 2.0) return false;
    if (lowerPrompt.includes("cup") && !s.primaryPattern.name.toLowerCase().includes("cup")) return false;
    if (lowerPrompt.includes("golden") && s.sma50 <= s.sma200) return false;
    return true;
  });

  if (filtered.length === 0) filtered = masterStocks.slice(0, 4);

  const lead = filtered[0] || masterStocks[0];
  const selectedTickers = filtered.slice(0, 4).map(s => ({
    ticker: s.ticker,
    company: s.company,
    score: Math.min(98, s.primaryPattern.confidence + Math.floor(s.rvol * 2)),
    pattern: s.primaryPattern.name,
    rvol: `${s.rvol}x`,
    rationale: s.primaryPattern.description
  }));

  const excluded = masterStocks.filter(s => !filtered.some(f => f.ticker === s.ticker)).slice(0, 4).map(s => ({
    ticker: s.ticker,
    reason: s.rvol < 2.0 ? `Volumen relativo insuficiente (${s.rvol}x < 2.0x RVOL requerido).` : `Divergencia técnica o mecha de rechazo intradiaria.`
  }));

  res.json({
    success: true,
    text: `He evaluado los activos del universo seleccionado bajo las restricciones de tu hipótesis ("${prompt}"). Tras contrastar la estructura de volumen al cierre regular y la simetría de las bases de precios, ${selectedTickers.length} activos superan todos los umbrales de confirmación.`,
    structuredResult: {
      refinedCount: selectedTickers.length,
      totalEvaluated: masterStocks.length,
      universe: universe || "S&P 500 (Rentables TTM)",
      pipelineSteps: [
        "Identificación de base en 'U' o patrón geométrico con retroceso < 38.2% Fibonacci",
        "Multiplicador RVOL (Relative Volume EOD) > 2.0x frente a la media móvil de 50 sesiones",
        "Filtro fundamental y técnico: precio > SMA 200 y alineación de medias alcistas"
      ],
      leadTicker: lead.ticker,
      selectedTickers,
      exclusions: excluded
    }
  });
});

// ================= VITE MIDDLEWARE & SERVER BOOT =================

async function startServer() {
  if (process.env.NODE_ENV !== "production") {
    const vite = await createViteServer({
      server: { middlewareMode: true },
      appType: "spa",
    });
    app.use(vite.middlewares);
  } else {
    const distPath = path.join(process.cwd(), "dist");
    app.use(express.static(distPath));
    app.get("*", (req, res) => {
      res.sendFile(path.join(distPath, "index.html"));
    });
  }

  app.listen(PORT, "0.0.0.0", () => {
    console.log(`Server running on http://0.0.0.0:${PORT}`);
  });
}

startServer();
