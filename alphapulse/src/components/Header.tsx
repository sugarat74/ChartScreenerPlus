import React from "react";
import { Filter, TrendingUp, Sparkles, Server, User, RefreshCw, Layers } from "lucide-react";

interface HeaderProps {
  activeTab: "screener" | "chart" | "copilot" | "admin" | "portal";
  setActiveTab: (tab: "screener" | "chart" | "copilot" | "admin" | "portal") => void;
  onTriggerScrape: () => void;
  isScraping: boolean;
  totalTickersCount: number;
}

export const Header: React.FC<HeaderProps> = ({
  activeTab,
  setActiveTab,
  onTriggerScrape,
  isScraping,
  totalTickersCount
}) => {
  return (
    <header className="w-full bg-[#f5f0e8] border-b-2 border-primary sticky top-0 z-40">
      {/* Top Banner Bar */}
      <div className="max-w-7xl mx-auto px-4 py-2.5 flex flex-wrap items-center justify-between gap-4">
        {/* Brand & Identity */}
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 bg-primary text-white border-2 border-primary rounded-md flex items-center justify-center font-headline font-black text-xl shadow-[2px_2px_0px_#ffcc00]">
            αP
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h1 className="font-headline font-black text-xl tracking-tight text-primary">
                ALPHAPULSE
              </h1>
              <span className="bg-[#ffcc00] text-primary text-[10px] font-mono font-bold px-1.5 py-0.5 border border-primary rounded shadow-[1px_1px_0px_#1a1a1a]">
                EOD PRO
              </span>
            </div>
            <p className="text-xs text-neutral-600 font-mono hidden sm:block">
              Screener Cuantitativo & Algoritmo de Chartismo EOD
            </p>
          </div>
        </div>

        {/* Live Market & Scraping Status Indicator */}
        <div className="flex items-center gap-3">
          <div className="hidden md:flex items-center gap-2 bg-[#eee9e0] border border-primary px-2.5 py-1 rounded text-xs font-mono">
            <span className="relative flex h-2 w-2">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
              <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-600"></span>
            </span>
            <span className="text-primary font-medium">Mercado Cerrado (EOD 21:00 UTC)</span>
            <span className="text-neutral-400">|</span>
            <span className="text-neutral-700 font-bold">{totalTickersCount.toLocaleString()} Activos</span>
          </div>

          {/* Direct Scrape Action Button */}
          <button
            onClick={onTriggerScrape}
            disabled={isScraping}
            className={`flex items-center gap-1.5 px-3 py-1.5 text-xs font-headline font-bold uppercase tracking-wider border-2 border-primary rounded transition-all shadow-[2px_2px_0px_#1a1a1a] active:translate-x-0.5 active:translate-y-0.5 ${
              isScraping
                ? "bg-amber-300 text-primary cursor-wait"
                : "bg-[#ffcc00] text-primary hover:bg-[#ffd633]"
            }`}
          >
            <RefreshCw className={`w-3.5 h-3.5 ${isScraping ? "animate-spin" : ""}`} />
            <span>{isScraping ? "Scraping EOD..." : "Actualizar EOD"}</span>
          </button>

          {/* User Profile Pill */}
          <button
            onClick={() => setActiveTab("portal")}
            className="flex items-center gap-2 pl-1 pr-2.5 py-1 bg-white border-2 border-primary rounded-full shadow-[2px_2px_0px_#1a1a1a] hover:bg-[#faf7f2] transition-colors"
          >
            <div className="w-6 h-6 rounded-full bg-primary text-white flex items-center justify-center font-bold text-xs">
              C
            </div>
            <span className="text-xs font-headline font-bold text-primary hidden sm:inline">
              Carlos M.
            </span>
            <span className="bg-emerald-100 text-emerald-800 text-[10px] font-bold px-1 rounded border border-emerald-300">
              PRO
            </span>
          </button>
        </div>
      </div>

      {/* Navigation Tabs Bar */}
      <div className="max-w-7xl mx-auto px-4 flex items-center gap-1 overflow-x-auto no-scrollbar border-t border-[#e2ddd4]">
        <button
          onClick={() => setActiveTab("screener")}
          className={`flex items-center gap-2 px-4 py-2.5 font-headline font-bold text-xs uppercase tracking-wider border-b-2 transition-all whitespace-nowrap ${
            activeTab === "screener"
              ? "border-primary text-primary bg-[#eee9e0]"
              : "border-transparent text-neutral-600 hover:text-primary hover:bg-[#faf7f2]"
          }`}
        >
          <Filter className="w-4 h-4" />
          <span>Screener Técnico</span>
        </button>

        <button
          onClick={() => setActiveTab("chart")}
          className={`flex items-center gap-2 px-4 py-2.5 font-headline font-bold text-xs uppercase tracking-wider border-b-2 transition-all whitespace-nowrap ${
            activeTab === "chart"
              ? "border-primary text-primary bg-[#eee9e0]"
              : "border-transparent text-neutral-600 hover:text-primary hover:bg-[#faf7f2]"
          }`}
        >
          <TrendingUp className="w-4 h-4" />
          <span>Gráfico Interactivo</span>
        </button>

        <button
          onClick={() => setActiveTab("copilot")}
          className={`flex items-center gap-2 px-4 py-2.5 font-headline font-bold text-xs uppercase tracking-wider border-b-2 transition-all whitespace-nowrap ${
            activeTab === "copilot"
              ? "border-primary text-primary bg-[#ffdad6]"
              : "border-transparent text-neutral-600 hover:text-primary hover:bg-[#faf7f2]"
          }`}
        >
          <Sparkles className="w-4 h-4 text-secondary" />
          <span>Copilot IA (Gemini)</span>
          <span className="bg-secondary text-white text-[9px] font-mono px-1 py-0.2 rounded font-bold">
            LLM
          </span>
        </button>

        <button
          onClick={() => setActiveTab("admin")}
          className={`flex items-center gap-2 px-4 py-2.5 font-headline font-bold text-xs uppercase tracking-wider border-b-2 transition-all whitespace-nowrap ${
            activeTab === "admin"
              ? "border-primary text-primary bg-[#d6e3ff]"
              : "border-transparent text-neutral-600 hover:text-primary hover:bg-[#faf7f2]"
          }`}
        >
          <Server className="w-4 h-4 text-tertiary" />
          <span>Admin Scraping & Pipeline</span>
        </button>

        <button
          onClick={() => setActiveTab("portal")}
          className={`flex items-center gap-2 px-4 py-2.5 font-headline font-bold text-xs uppercase tracking-wider border-b-2 transition-all whitespace-nowrap ${
            activeTab === "portal"
              ? "border-primary text-primary bg-[#eee9e0]"
              : "border-transparent text-neutral-600 hover:text-primary hover:bg-[#faf7f2]"
          }`}
        >
          <User className="w-4 h-4" />
          <span>Mi Portal & Seguimientos</span>
        </button>
      </div>
    </header>
  );
};
