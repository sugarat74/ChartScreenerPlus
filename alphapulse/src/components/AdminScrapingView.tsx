import React, { useState, useEffect } from "react";
import { ScrapingTelemetry, ScrapingLog, ScrapingUniverse } from "../types";
import { 
  Server, 
  Terminal, 
  Play, 
  RefreshCw, 
  Trash2, 
  PlusCircle, 
  CheckCircle, 
  AlertTriangle, 
  ShieldCheck, 
  Activity, 
  Cpu, 
  Globe,
  Database,
  Radio
} from "lucide-react";

interface Props {
  onTriggerScrape: (source: string, universe: string) => void;
  isScraping: boolean;
  onRecalcPatterns: () => void;
  onClearCache: () => void;
  onAddCustomTicker: (data: { ticker: string; exchange: string; company: string; sector: string }) => void;
}

export const AdminScrapingView: React.FC<Props> = ({
  onTriggerScrape,
  isScraping,
  onRecalcPatterns,
  onClearCache,
  onAddCustomTicker
}) => {
  const [telemetry, setTelemetry] = useState<ScrapingTelemetry>({
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
  });

  const [logs, setLogs] = useState<ScrapingLog[]>([]);
  const [universes, setUniverses] = useState<ScrapingUniverse[]>([]);
  const [selectedSource, setSelectedSource] = useState("Yahoo Finance EOD API");
  const [selectedUniverse, setSelectedUniverse] = useState("Universo Completo (4,820 símbolos)");
  const [logFilter, setLogFilter] = useState<string>("ALL");

  // Add ticker form state
  const [newTicker, setNewTicker] = useState("");
  const [newExchange, setNewExchange] = useState("NASDAQ");
  const [newCompany, setNewCompany] = useState("");
  const [newSector, setNewSector] = useState("Tecnología / Software");
  const [addTickerFeedback, setAddTickerFeedback] = useState<string | null>(null);

  // Load telemetry from server
  const fetchTelemetry = async () => {
    try {
      const res = await fetch("/api/scrape/telemetry");
      const data = await res.json();
      if (data.telemetry) setTelemetry(data.telemetry);
      if (data.logs) setLogs(data.logs);
      if (data.universes) setUniverses(data.universes);
    } catch (err) {
      console.error("Error fetching telemetry:", err);
    }
  };

  useEffect(() => {
    fetchTelemetry();
    const interval = setInterval(fetchTelemetry, 3500);
    return () => clearInterval(interval);
  }, []);

  const handleExecuteScrape = () => {
    onTriggerScrape(selectedSource, selectedUniverse);
    setTimeout(fetchTelemetry, 800);
  };

  const handleAddTickerSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newTicker.trim()) return;

    onAddCustomTicker({
      ticker: newTicker.toUpperCase().trim(),
      exchange: newExchange,
      company: newCompany.trim() || `${newTicker.toUpperCase()} Inc.`,
      sector: newSector
    });

    setAddTickerFeedback(`Símbolo ${newTicker.toUpperCase()} añadido a la cola de ingesta EOD.`);
    setNewTicker("");
    setNewCompany("");
    setTimeout(() => {
      setAddTickerFeedback(null);
      fetchTelemetry();
    }, 2500);
  };

  const filteredLogs = logs.filter(log => {
    if (logFilter === "ALL") return true;
    return log.level === logFilter;
  });

  return (
    <div className="space-y-6">
      {/* Top Banner Alert / Pipeline Status */}
      <div className="bg-[#1a1a1a] text-white p-4 rounded-lg border-2 border-primary flex flex-wrap items-center justify-between gap-4 shadow-[4px_4px_0px_#ffcc00]">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded bg-[#ffcc00] text-primary flex items-center justify-center font-bold">
            <Radio className="w-5 h-5 animate-pulse" />
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h2 className="font-headline font-black text-lg tracking-wide uppercase">
                Panel de Control: Ingesta EOD &amp; Scraper Pipeline
              </h2>
              <span className="bg-emerald-500 text-white text-[10px] font-mono font-bold px-2 py-0.5 rounded">
                {isScraping ? "SCRAPING EN CURSO" : telemetry.systemStatus}
              </span>
            </div>
            <p className="text-xs font-mono text-neutral-300">
              Scraping de cotizaciones de final de día (EOD). Sin peticiones intradía para máxima estabilidad y cumplimiento de cuotas.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={fetchTelemetry}
            className="px-3 py-1.5 bg-[#333] hover:bg-[#444] text-xs font-mono rounded border border-neutral-600 flex items-center gap-1.5 transition-colors"
          >
            <RefreshCw className="w-3.5 h-3.5" />
            <span>Sincronizar</span>
          </button>
        </div>
      </div>

      {/* 4 Telemetry Metric Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {/* Workers Status */}
        <div className="bg-white border-2 border-primary p-4 rounded-lg shadow-[3px_3px_0px_#1a1a1a]">
          <div className="flex items-center justify-between text-xs text-neutral-600 font-mono">
            <span>WORKERS ASÍNCRONOS</span>
            <Cpu className="w-4 h-4 text-emerald-600" />
          </div>
          <div className="flex items-baseline gap-2 mt-2">
            <span className="font-headline font-black text-3xl text-primary">
              {telemetry.workersActive}/{telemetry.totalWorkers}
            </span>
            <span className="text-xs font-mono text-emerald-700 font-bold bg-emerald-50 px-1.5 py-0.5 rounded border border-emerald-200">
              100% Carga
            </span>
          </div>
          <p className="text-[11px] text-neutral-500 mt-1">Pool concurrente de peticiones HTTP</p>
        </div>

        {/* Tickers Indexed */}
        <div className="bg-white border-2 border-primary p-4 rounded-lg shadow-[3px_3px_0px_#1a1a1a]">
          <div className="flex items-center justify-between text-xs text-neutral-600 font-mono">
            <span>SÍMBOLOS EOD PROCESADOS</span>
            <Database className="w-4 h-4 text-[#0055ff]" />
          </div>
          <div className="flex items-baseline gap-2 mt-2">
            <span className="font-headline font-black text-3xl text-primary">
              {telemetry.tickersProcessed.toLocaleString()}
            </span>
            <span className="text-xs font-mono text-[#0055ff] font-bold bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200">
              Último: {telemetry.lastScrapeAgo}
            </span>
          </div>
          <p className="text-[11px] text-neutral-500 mt-1">Velas OHLCV + Volumen EOD</p>
        </div>

        {/* Throughput & Duration */}
        <div className="bg-white border-2 border-primary p-4 rounded-lg shadow-[3px_3px_0px_#1a1a1a]">
          <div className="flex items-center justify-between text-xs text-neutral-600 font-mono">
            <span>THROUGHPUT &amp; DURACIÓN</span>
            <Activity className="w-4 h-4 text-amber-600" />
          </div>
          <div className="flex items-baseline gap-2 mt-2">
            <span className="font-headline font-black text-3xl text-primary">{telemetry.throughput}</span>
            <span className="text-xs font-mono text-neutral-600 font-bold bg-[#eee9e0] px-1.5 py-0.5 rounded">
              {telemetry.jobDuration}
            </span>
          </div>
          <p className="text-[11px] text-neutral-500 mt-1">Delta ayer: {telemetry.deltaYesterday}</p>
        </div>

        {/* Proxy Health */}
        <div className="bg-white border-2 border-primary p-4 rounded-lg shadow-[3px_3px_0px_#1a1a1a]">
          <div className="flex items-center justify-between text-xs text-neutral-600 font-mono">
            <span>RED DE PROXIES RESIDENCIALES</span>
            <Globe className="w-4 h-4 text-purple-600" />
          </div>
          <div className="flex items-baseline gap-2 mt-2">
            <span className="font-headline font-black text-3xl text-primary">
              {telemetry.proxyActive}/{telemetry.proxyTotal}
            </span>
            <span className="text-xs font-mono text-purple-700 font-bold bg-purple-50 px-1.5 py-0.5 rounded border border-purple-200">
              {telemetry.proxyLatency}ms Lat.
            </span>
          </div>
          <p className="text-[11px] text-neutral-500 mt-1">Tasa de error: {telemetry.errorRate} | Bans: 0</p>
        </div>
      </div>

      {/* Scraping Job Controls & Actions */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left 2 Cols: Manual Trigger & Settings */}
        <div className="lg:col-span-2 bg-white border-2 border-primary rounded-lg p-5 shadow-[4px_4px_0px_#1a1a1a] space-y-5">
          <div className="border-b-2 border-primary pb-3 flex items-center justify-between">
            <div>
              <h3 className="font-headline font-black text-lg text-primary uppercase">
                Disparador Manual de Ingesta EOD
              </h3>
              <p className="text-xs text-neutral-600 font-mono">
                Lanza una corrida completa de scraping tras el toque de campana de cierre (21:00 UTC).
              </p>
            </div>
            <Server className="w-5 h-5 text-neutral-400" />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs font-mono">
            <div>
              <label className="font-bold text-neutral-700 block mb-1">
                Proveedor de Cotizaciones EOD:
              </label>
              <select
                value={selectedSource}
                onChange={(e) => setSelectedSource(e.target.value)}
                className="w-full p-2 bg-[#faf7f2] border-2 border-primary rounded font-bold cursor-pointer"
              >
                <option value="Yahoo Finance EOD API">Yahoo Finance EOD (Histórico 5 Años)</option>
                <option value="Nasdaq Official EOD Feed">Nasdaq Official EOD Feed</option>
                <option value="CBOE / BATS End-of-Day">CBOE / BATS End-of-Day</option>
                <option value="BME Continuo (España)">BME Continuo (Mercado Español)</option>
              </select>
            </div>

            <div>
              <label className="font-bold text-neutral-700 block mb-1">
                Universo Objetivo:
              </label>
              <select
                value={selectedUniverse}
                onChange={(e) => setSelectedUniverse(e.target.value)}
                className="w-full p-2 bg-[#faf7f2] border-2 border-primary rounded font-bold cursor-pointer"
              >
                <option value="Universo Completo (4,820 símbolos)">Universo Completo (4,820 símbolos)</option>
                <option value="S&P 500 (503 símbolos)">S&amp;P 500 (503 símbolos)</option>
                <option value="NASDAQ 100 (101 símbolos)">NASDAQ 100 (101 símbolos)</option>
                <option value="Russell 2000 (1,982 símbolos)">Russell 2000 (1,982 símbolos)</option>
                <option value="IBEX 35 (35 símbolos)">IBEX 35 &amp; Continuo</option>
              </select>
            </div>
          </div>

          {/* Action Buttons */}
          <div className="pt-2 flex flex-wrap items-center gap-3">
            <button
              onClick={handleExecuteScrape}
              disabled={isScraping}
              className={`flex items-center gap-2 px-5 py-2.5 rounded font-headline font-bold text-xs uppercase tracking-wider border-2 border-primary shadow-[3px_3px_0px_#1a1a1a] transition-all ${
                isScraping
                  ? "bg-amber-300 text-primary cursor-wait"
                  : "bg-[#ffcc00] hover:bg-[#ffd633] text-primary"
              }`}
            >
              <Play className={`w-4 h-4 fill-primary ${isScraping ? "animate-spin" : ""}`} />
              <span>{isScraping ? "Scraping EOD en ejecución..." : "Ejecutar Pipeline EOD Ahora"}</span>
            </button>

            <button
              onClick={onRecalcPatterns}
              className="flex items-center gap-1.5 px-4 py-2.5 bg-[#eee9e0] hover:bg-[#e2ddd4] text-primary rounded font-headline font-bold text-xs uppercase tracking-wider border-2 border-primary shadow-[2px_2px_0px_#1a1a1a] transition-all"
            >
              <RefreshCw className="w-3.5 h-3.5" />
              <span>Recalcular Patrones Técnicos</span>
            </button>

            <button
              onClick={onClearCache}
              className="flex items-center gap-1.5 px-4 py-2.5 bg-[#fff5f5] hover:bg-[#fee2e2] text-rose-700 rounded font-headline font-bold text-xs uppercase tracking-wider border-2 border-rose-300 transition-all ml-auto"
            >
              <Trash2 className="w-3.5 h-3.5" />
              <span>Purgar Caché Redis</span>
            </button>
          </div>

          {/* Universes Management Table */}
          <div className="pt-4 border-t-2 border-primary">
            <span className="text-xs font-headline font-bold uppercase tracking-wider text-neutral-700 block mb-2">
              Universos Administrados y Frecuencia de Sincronización:
            </span>
            <div className="border border-primary rounded overflow-hidden">
              <table className="w-full text-left text-xs font-mono">
                <thead className="bg-[#eee9e0] border-b border-primary text-primary font-bold">
                  <tr>
                    <th className="py-2 px-3">Universo</th>
                    <th className="py-2 px-3">Símbolos</th>
                    <th className="py-2 px-3">Última Sincro</th>
                    <th className="py-2 px-3">Salud</th>
                    <th className="py-2 px-3 text-right">Auto-Scrape</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#eee9e0]">
                  {universes.map((u) => (
                    <tr key={u.id} className="hover:bg-[#faf7f2]">
                      <td className="py-2 px-3 font-bold text-primary">{u.name}</td>
                      <td className="py-2 px-3">{u.symbolCount}</td>
                      <td className="py-2 px-3 text-neutral-600">{u.lastSync}</td>
                      <td className="py-2 px-3">
                        <span className="text-emerald-700 font-semibold">{u.health}</span>
                      </td>
                      <td className="py-2 px-3 text-right">
                        <span className="bg-emerald-100 text-emerald-800 text-[10px] font-bold px-1.5 py-0.5 rounded border border-emerald-300">
                          ACTIVO (21:00 UTC)
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        {/* Right Col: Add Custom Ticker Form */}
        <div className="bg-white border-2 border-primary rounded-lg p-5 shadow-[4px_4px_0px_#1a1a1a] space-y-4">
          <div className="border-b-2 border-primary pb-3">
            <h3 className="font-headline font-black text-lg text-primary uppercase flex items-center gap-2">
              <PlusCircle className="w-5 h-5 text-emerald-600" />
              Añadir Símbolo
            </h3>
            <p className="text-xs text-neutral-600 font-mono">
              Registra un nuevo ticker para su descarga y análisis chartista EOD.
            </p>
          </div>

          <form onSubmit={handleAddTickerSubmit} className="space-y-3 text-xs font-mono">
            <div>
              <label className="font-bold text-neutral-700 block mb-1">Ticker / Símbolo:</label>
              <input
                type="text"
                required
                value={newTicker}
                onChange={(e) => setNewTicker(e.target.value.toUpperCase())}
                placeholder="Ej. PLTR, SMCI, SAN.MC..."
                className="w-full p-2 bg-[#faf7f2] border-2 border-primary rounded font-bold uppercase focus:outline-none focus:bg-white"
              />
            </div>

            <div>
              <label className="font-bold text-neutral-700 block mb-1">Bolsa / Mercado:</label>
              <select
                value={newExchange}
                onChange={(e) => setNewExchange(e.target.value)}
                className="w-full p-2 bg-[#faf7f2] border-2 border-primary rounded font-bold cursor-pointer"
              >
                <option value="NASDAQ">NASDAQ</option>
                <option value="NYSE">NYSE (New York Stock Exchange)</option>
                <option value="BME">BME / Mercado Continuo (España)</option>
                <option value="AMEX">AMEX</option>
              </select>
            </div>

            <div>
              <label className="font-bold text-neutral-700 block mb-1">Nombre de Empresa (Opcional):</label>
              <input
                type="text"
                value={newCompany}
                onChange={(e) => setNewCompany(e.target.value)}
                placeholder="Ej. Palantir Technologies Inc."
                className="w-full p-2 bg-[#faf7f2] border-2 border-primary rounded focus:outline-none focus:bg-white"
              />
            </div>

            <div>
              <label className="font-bold text-neutral-700 block mb-1">Sector:</label>
              <input
                type="text"
                value={newSector}
                onChange={(e) => setNewSector(e.target.value)}
                placeholder="Ej. Semiconductores, Software..."
                className="w-full p-2 bg-[#faf7f2] border-2 border-primary rounded focus:outline-none focus:bg-white"
              />
            </div>

            <button
              type="submit"
              className="w-full py-2.5 bg-primary hover:bg-neutral-800 text-white rounded font-headline font-bold text-xs uppercase tracking-wider shadow-[2px_2px_0px_#ffcc00] transition-all"
            >
              Registrar en Cola EOD
            </button>

            {addTickerFeedback && (
              <div className="p-2 bg-emerald-50 border border-emerald-400 rounded text-emerald-800 text-xs font-mono font-bold flex items-center gap-1.5">
                <CheckCircle className="w-3.5 h-3.5 text-emerald-600" />
                <span>{addTickerFeedback}</span>
              </div>
            )}
          </form>
        </div>
      </div>

      {/* Terminal Logs Viewer */}
      <div className="bg-[#1a1a1a] text-white border-2 border-primary rounded-lg overflow-hidden shadow-[4px_4px_0px_#1a1a1a]">
        {/* Terminal Header */}
        <div className="bg-[#262626] px-4 py-2.5 border-b border-neutral-700 flex flex-wrap items-center justify-between gap-3 text-xs font-mono">
          <div className="flex items-center gap-2">
            <Terminal className="w-4 h-4 text-[#ffcc00]" />
            <span className="font-bold text-neutral-200">
              Terminal de Salida: Logs del Demonio de Scraping (daemon.log)
            </span>
          </div>

          <div className="flex items-center gap-1.5">
            <span className="text-neutral-400 text-[11px]">Nivel:</span>
            {["ALL", "INFO", "SUCCESS", "WARN", "CALC", "TASK"].map((lvl) => (
              <button
                key={lvl}
                onClick={() => setLogFilter(lvl)}
                className={`px-2 py-0.5 rounded text-[10px] font-bold transition-all ${
                  logFilter === lvl
                    ? "bg-[#ffcc00] text-primary"
                    : "bg-[#333] text-neutral-400 hover:text-white"
                }`}
              >
                {lvl}
              </button>
            ))}
          </div>
        </div>

        {/* Terminal Log Lines */}
        <div className="p-4 font-mono text-xs max-h-72 overflow-y-auto space-y-1.5 bg-[#141414]">
          {filteredLogs.map((log) => {
            const levelColor = 
              log.level === "SUCCESS" ? "text-emerald-400 bg-emerald-950/60 border-emerald-700" :
              log.level === "WARN" || log.level === "RETRY" ? "text-amber-400 bg-amber-950/60 border-amber-700" :
              log.level === "CALC" ? "text-purple-400 bg-purple-950/60 border-purple-700" :
              log.level === "TASK" || log.level === "FETCH" ? "text-cyan-400 bg-cyan-950/60 border-cyan-700" :
              log.level === "REDIS" ? "text-rose-400 bg-rose-950/60 border-rose-700" :
              "text-blue-400 bg-blue-950/60 border-blue-700";

            return (
              <div key={log.id} className="flex items-start gap-3 hover:bg-neutral-900/60 py-0.5 px-1 rounded">
                <span className="text-neutral-500 shrink-0">{log.timestamp}</span>
                <span className={`px-1.5 py-0.2 rounded border text-[10px] font-bold shrink-0 ${levelColor}`}>
                  {log.level}
                </span>
                <span className="text-neutral-200">{log.message}</span>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
};
