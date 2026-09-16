import React, { useState } from "react";
import { StockItem, CopilotMessage } from "../types";
import { 
  Sparkles, 
  Send, 
  Layers, 
  ArrowRight, 
  CheckCircle2, 
  XCircle, 
  BarChart2, 
  Cpu, 
  ChevronRight,
  TrendingUp,
  Sliders,
  AlertCircle
} from "lucide-react";

interface Props {
  stocks: StockItem[];
  onSelectStock: (stock: StockItem) => void;
  initialTicker?: string;
}

export const CopilotIAView: React.FC<Props> = ({
  stocks,
  onSelectStock,
  initialTicker
}) => {
  const [prompt, setPrompt] = useState(
    initialTicker
      ? `Evaluar el patrón chartista en ${initialTicker}, analizando el volumen institucional EOD, riesgo de falso breakout y potencial ratio R:R.`
      : "Identificar formaciones de Cup and Handle con volumen superior a 2.0x RVOL y ausencia de resistencia cercana en líderes tecnológicos."
  );
  const [universe, setUniverse] = useState("S&P 500 & Nasdaq 100");
  const [strictVolume, setStrictVolume] = useState(true);
  const [isLoading, setIsLoading] = useState(false);

  // Chat conversation state
  const [messages, setMessages] = useState<CopilotMessage[]>([
    {
      id: "msg-welcome",
      sender: "ai",
      time: "21:05 UTC",
      text: "¡Hola, Carlos! Soy tu Copilot de Chartismo Cuantitativo impulsado por Gemini. Puedo evaluar tus hipótesis de trading, filtrar rupturas falsas mediante análisis de volumen EOD y calcular ratios riesgo/beneficio en los 4,820 activos de la base de datos.",
      structuredResult: {
        refinedCount: 3,
        totalEvaluated: 14,
        universe: "S&P 500 & Nasdaq 100",
        pipelineSteps: [
          "Identificación de base en 'U' o patrón geométrico con retroceso < 38.2% Fibonacci",
          "Filtro de confirmación EOD: RVOL > 2.0x y cierre en el 80% superior del rango diario",
          "Descarte de falsas rupturas por sobrecompra extrema o resistencias inmediatas"
        ],
        leadTicker: "CRWD",
        selectedTickers: [
          {
            ticker: "CRWD",
            company: "CrowdStrike Holdings",
            score: 95,
            pattern: "Cup & Handle",
            rvol: "3.48x",
            rationale: "Ruptura de base de 63 días con volumen 3.48x superior al promedio. Asa con compresión milimétrica de volatilidad."
          },
          {
            ticker: "NVDA",
            company: "NVIDIA Corp.",
            score: 94,
            pattern: "Cup and Handle",
            rvol: "3.40x",
            rationale: "Ruptura de resistencia pivotal en $125.80. Volumen institucional masivo y ausencia de resistencia histórica."
          },
          {
            ticker: "PLTR",
            company: "Palantir Technologies",
            score: 91,
            pattern: "Cup and Handle",
            rvol: "2.40x",
            rationale: "Ruptura confirmada en $27.20. Flujo comprador continuo tras inclusión en índices primarios."
          }
        ],
        exclusions: [
          {
            ticker: "AAPL",
            reason: "RVOL de 1.8x inferior al umbral de 2.0x requerido. En pullback a SMA 50 sin confirmación de giro."
          },
          {
            ticker: "TSLA",
            reason: "Patrón de directriz bajista superado pero con mecha superior de venta en cierre intradiario."
          }
        ]
      }
    }
  ]);

  const handleRunRefinement = async (customPrompt?: string) => {
    const textToSubmit = customPrompt || prompt;
    if (!textToSubmit.trim() || isLoading) return;

    const userMsg: CopilotMessage = {
      id: `usr-${Date.now()}`,
      sender: "user",
      time: new Date().toTimeString().split(" ")[0].slice(0, 5),
      text: textToSubmit
    };

    setMessages(prev => [...prev, userMsg]);
    setIsLoading(true);

    try {
      const res = await fetch("/api/copilot/reason", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          prompt: textToSubmit,
          universe,
          strictVolume
        })
      });

      const data = await res.json();

      const aiMsg: CopilotMessage = {
        id: `ai-${Date.now()}`,
        sender: "ai",
        time: new Date().toTimeString().split(" ")[0].slice(0, 5),
        text: data.text || "He procesado el universo con tu criterio cuantitativo.",
        structuredResult: data.structuredResult
      };

      setMessages(prev => [...prev, aiMsg]);
    } catch (err) {
      console.error("Error al consultar Copilot:", err);
      const errorMsg: CopilotMessage = {
        id: `err-${Date.now()}`,
        sender: "ai",
        time: new Date().toTimeString().split(" ")[0].slice(0, 5),
        text: "Hubo una incidencia al procesar la solicitud con Gemini. Aplicando motor heurístico local sobre los datos EOD."
      };
      setMessages(prev => [...prev, errorMsg]);
    } finally {
      setIsLoading(false);
    }
  };

  const samplePrompts = [
    "Refinar Cup & Handle con volumen institucional > 2.5x RVOL en empresas de software e IA",
    "Detectar roturas con bajo riesgo de falso breakout y ratio R:R superior a 3:1",
    "Filtrar cruces dorados (50/200) con consolidación sana sobre la EMA 21",
    "Localizar dobles suelos con divergencia alcista en MACD y RSI en zona de valor"
  ];

  return (
    <div className="space-y-6">
      {/* Top Copilot Header Card */}
      <div className="bg-white border-2 border-primary rounded-lg p-5 shadow-[4px_4px_0px_#1a1a1a]">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className="w-12 h-12 rounded-lg bg-[#ffdad6] border-2 border-secondary flex items-center justify-center text-secondary shadow-[2px_2px_0px_#e63b2e]">
              <Sparkles className="w-6 h-6" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h2 className="font-headline font-black text-2xl text-primary">
                  AlphaPulse Copilot IA
                </h2>
                <span className="bg-secondary text-white font-mono text-[10px] font-bold px-2 py-0.5 rounded border border-secondary">
                  Gemini 3.8 Flash
                </span>
              </div>
              <p className="text-xs text-neutral-600 font-sans mt-0.5">
                Refinamiento semántico y algorítmico de patrones chartistas sobre 4,820 activos EOD.
              </p>
            </div>
          </div>

          {/* Quick Settings */}
          <div className="flex flex-wrap items-center gap-3 text-xs font-mono">
            <div className="flex items-center gap-1.5 bg-[#faf7f2] border border-primary px-3 py-1.5 rounded">
              <span className="text-neutral-500 font-bold">Universo:</span>
              <select
                value={universe}
                onChange={(e) => setUniverse(e.target.value)}
                className="bg-transparent font-bold text-primary focus:outline-none cursor-pointer"
              >
                <option value="S&P 500 & Nasdaq 100">S&amp;P 500 &amp; Nasdaq 100</option>
                <option value="Russell 2000">Russell 2000 Small Caps</option>
                <option value="IBEX 35">IBEX 35 Continuo</option>
                <option value="Todos los 4,820 activos">Todos los 4,820 activos</option>
              </select>
            </div>

            <button
              onClick={() => setStrictVolume(!strictVolume)}
              className={`flex items-center gap-1.5 px-3 py-1.5 rounded border transition-all ${
                strictVolume
                  ? "bg-emerald-50 text-emerald-900 border-emerald-500 font-bold"
                  : "bg-neutral-100 text-neutral-600 border-neutral-300"
              }`}
            >
              <CheckCircle2 className={`w-3.5 h-3.5 ${strictVolume ? "text-emerald-600" : "text-neutral-400"}`} />
              <span>Confirmación Estricta RVOL &gt; 2.0x</span>
            </button>
          </div>
        </div>

        {/* Prompt Input Form */}
        <div className="mt-5 space-y-3">
          <div className="relative">
            <textarea
              rows={3}
              value={prompt}
              onChange={(e) => setPrompt(e.target.value)}
              placeholder="Describe tu hipótesis cuantitativa o los criterios de chartismo que deseas refinar..."
              className="w-full p-3 text-xs font-mono bg-[#faf7f2] border-2 border-primary rounded-lg focus:outline-none focus:bg-white resize-none shadow-inner"
            />
            <button
              onClick={() => handleRunRefinement()}
              disabled={isLoading || !prompt.trim()}
              className="absolute right-3 bottom-4 px-4 py-2 bg-secondary hover:bg-[#d63024] disabled:bg-neutral-300 text-white border-2 border-primary rounded font-headline font-bold text-xs uppercase tracking-wider shadow-[2px_2px_0px_#1a1a1a] transition-all flex items-center gap-1.5"
            >
              <Sparkles className="w-3.5 h-3.5" />
              <span>{isLoading ? "Razonando..." : "Refinar Patrones"}</span>
            </button>
          </div>

          {/* Quick Preset Prompts */}
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-[11px] font-mono text-neutral-500">Ejemplos cuantitativos:</span>
            {samplePrompts.map((p, i) => (
              <button
                key={i}
                onClick={() => {
                  setPrompt(p);
                  handleRunRefinement(p);
                }}
                className="text-[11px] font-sans bg-[#f2ede5] hover:bg-[#e8e3da] text-neutral-700 px-2.5 py-1 rounded border border-[#d0cbc3] transition-colors"
              >
                {p}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Conversation & Structured Results Feed */}
      <div className="space-y-6">
        {messages.map((msg) => (
          <div
            key={msg.id}
            className={`border-2 border-primary rounded-lg p-5 shadow-[4px_4px_0px_#1a1a1a] ${
              msg.sender === "user" ? "bg-[#f2ede5]" : "bg-white"
            }`}
          >
            {/* Message Header */}
            <div className="flex items-center justify-between border-b border-[#eee9e0] pb-2 mb-3">
              <div className="flex items-center gap-2">
                {msg.sender === "user" ? (
                  <div className="w-6 h-6 rounded-full bg-primary text-white flex items-center justify-center font-bold text-xs">
                    C
                  </div>
                ) : (
                  <div className="w-6 h-6 rounded bg-[#ffdad6] text-secondary flex items-center justify-center font-bold text-xs border border-secondary">
                    <Sparkles className="w-3.5 h-3.5" />
                  </div>
                )}
                <span className="font-headline font-bold text-xs text-primary">
                  {msg.sender === "user" ? "Tu Hipótesis de Búsqueda" : "Dictamen Cuantitativo de Gemini"}
                </span>
              </div>
              <span className="text-[11px] font-mono text-neutral-400">{msg.time}</span>
            </div>

            {/* Text message */}
            <p className="text-xs text-neutral-800 leading-relaxed font-sans mb-4">
              {msg.text}
            </p>

            {/* Structured AI Analysis Cards (if available) */}
            {msg.structuredResult && (
              <div className="space-y-4 pt-3 border-t-2 border-primary">
                {/* Pipeline Flow Steps */}
                <div className="bg-[#faf7f2] border border-primary p-3 rounded-lg">
                  <div className="text-[11px] font-headline font-bold uppercase tracking-wider text-neutral-600 mb-2">
                    Pipeline de Filtrado Ejecutado:
                  </div>
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-2">
                    {msg.structuredResult.pipelineSteps.map((step, idx) => (
                      <div
                        key={idx}
                        className="bg-white p-2.5 rounded border border-[#d0cbc3] text-xs font-mono flex items-start gap-2"
                      >
                        <span className="w-5 h-5 rounded-full bg-primary text-white flex items-center justify-center text-[10px] font-bold shrink-0">
                          {idx + 1}
                        </span>
                        <span className="text-neutral-700 leading-tight">{step}</span>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Refined Candidates Grid */}
                <div>
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-xs font-headline font-bold uppercase tracking-wider text-primary">
                      Activos Seleccionados con Máxima Convicción ({msg.structuredResult.selectedTickers.length})
                    </span>
                    <span className="text-[11px] font-mono text-neutral-500">
                      Evaluados: {msg.structuredResult.totalEvaluated} activos en {msg.structuredResult.universe}
                    </span>
                  </div>

                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {msg.structuredResult.selectedTickers.map((tickerItem) => {
                      const matchedStock = stocks.find(s => s.ticker === tickerItem.ticker);

                      return (
                        <div
                          key={tickerItem.ticker}
                          className="bg-[#faf7f2] border-2 border-primary rounded-lg p-4 shadow-[2px_2px_0px_#1a1a1a] flex flex-col justify-between"
                        >
                          <div>
                            <div className="flex items-start justify-between">
                              <div>
                                <span className="font-headline font-black text-xl text-primary block">
                                  {tickerItem.ticker}
                                </span>
                                <span className="text-[11px] text-neutral-500 font-sans">
                                  {tickerItem.company}
                                </span>
                              </div>
                              <span className="bg-emerald-600 text-white font-mono font-bold text-xs px-2 py-0.5 rounded">
                                {tickerItem.score} pts
                              </span>
                            </div>

                            <div className="mt-3 flex items-center gap-2 text-xs font-mono">
                              <span className="bg-[#ffcc00] text-primary px-1.5 py-0.5 rounded font-bold border border-primary">
                                {tickerItem.pattern}
                              </span>
                              <span className="bg-amber-100 text-amber-900 px-1.5 py-0.5 rounded font-bold border border-amber-300">
                                RVOL {tickerItem.rvol}
                              </span>
                            </div>

                            <p className="mt-2.5 text-xs text-neutral-700 font-sans leading-relaxed">
                              {tickerItem.rationale}
                            </p>
                          </div>

                          <div className="mt-4 pt-3 border-t border-[#e2ddd4] flex items-center justify-between">
                            <span className="text-[11px] font-mono text-emerald-700 font-bold">
                              Ruptura Validada EOD
                            </span>
                            {matchedStock && (
                              <button
                                onClick={() => onSelectStock(matchedStock)}
                                className="px-2.5 py-1 bg-primary hover:bg-neutral-800 text-white rounded font-headline font-bold text-[10px] uppercase tracking-wider"
                              >
                                Ver Gráfico
                              </button>
                            )}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>

                {/* Exclusions Panel */}
                {msg.structuredResult.exclusions && msg.structuredResult.exclusions.length > 0 && (
                  <div className="bg-[#fff5f5] border-2 border-rose-300 rounded-lg p-3">
                    <span className="text-xs font-headline font-bold uppercase tracking-wider text-rose-800 block mb-2 flex items-center gap-1.5">
                      <XCircle className="w-3.5 h-3.5 text-rose-600" />
                      Filtro de Descarte Cuantitativo (Riesgo de Falso Breakout):
                    </span>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs font-mono">
                      {msg.structuredResult.exclusions.map((ex, idx) => (
                        <div key={idx} className="bg-white p-2 rounded border border-rose-200">
                          <strong className="text-rose-900 mr-1.5">{ex.ticker}:</strong>
                          <span className="text-neutral-600">{ex.reason}</span>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
};
