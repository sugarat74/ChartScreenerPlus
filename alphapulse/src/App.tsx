import React, { useState, useEffect } from "react";
import { Header } from "./components/Header";
import { ScreenerView } from "./components/ScreenerView";
import { InteractiveChartView } from "./components/InteractiveChartView";
import { CopilotIAView } from "./components/CopilotIAView";
import { AdminScrapingView } from "./components/AdminScrapingView";
import { UserPortalView } from "./components/UserPortalView";
import { StockItem, FilterState, SavedScreener, WatchlistItem, TriggeredAlert, UserProfile } from "./types";

export default function App() {
  const [activeTab, setActiveTab] = useState<"screener" | "chart" | "copilot" | "admin" | "portal">("screener");
  const [stocks, setStocks] = useState<StockItem[]>([]);
  const [isLoadingStocks, setIsLoadingStocks] = useState(true);
  const [selectedStock, setSelectedStock] = useState<StockItem | null>(null);
  const [copilotInitialTicker, setCopilotInitialTicker] = useState<string | undefined>(undefined);
  const [isScraping, setIsScraping] = useState(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  // User and persistence states
  const [userProfile, setUserProfile] = useState<UserProfile>({
    name: "Carlos M.",
    email: "carlos.trader@alphapulse.io",
    plan: "PRO TRADER",
    license: "Licencia PRO",
    id: "#TRD-9042-ALPHA",
    observedCapital: 342850.0,
    dailyPnL: 4190.2,
    dailyPnLPercent: 1.24,
    availableMargin: 88400.0,
    notificationSettings: {
      emailDigest: true,
      emailAddress: "carlos.trader@alphapulse.io",
      telegramWebhook: true,
      telegramBot: "@AlphaPulse_CarlosBot",
      browserPush: false
    }
  });

  const [savedScreeners, setSavedScreeners] = useState<SavedScreener[]>([]);
  const [watchlist, setWatchlist] = useState<WatchlistItem[]>([]);
  const [recentAlerts, setRecentAlerts] = useState<TriggeredAlert[]>([]);

  // Filter State
  const [filters, setFilters] = useState<FilterState>({
    searchQuery: "",
    universe: "all",
    priceAboveSma200: true,
    goldenCrossRecent: false,
    ema21AboveEma55: false,
    pullbackSma50: false,
    rsiRange: [0, 100],
    macdBullishCross: false,
    adxAbove25: false,
    minRvol: 1.0,
    selectedPatterns: [],
    sortBy: "rvol_desc"
  });

  // Fetch stocks from backend
  const fetchStocks = async () => {
    try {
      const queryParams = new URLSearchParams();
      if (filters.searchQuery) queryParams.set("search", filters.searchQuery);
      if (filters.selectedPatterns[0]) queryParams.set("pattern", filters.selectedPatterns[0]);
      if (filters.minRvol > 1.0) queryParams.set("minRvol", String(filters.minRvol));
      if (filters.priceAboveSma200) queryParams.set("sma200", "true");
      if (filters.goldenCrossRecent) queryParams.set("goldenCross", "true");
      queryParams.set("sortBy", filters.sortBy);

      const res = await fetch(`/api/stocks?${queryParams.toString()}`);
      const data = await res.json();
      if (data.data) {
        setStocks(data.data);
        if (!selectedStock && data.data.length > 0) {
          setSelectedStock(data.data[0]);
        }
      }
    } catch (err) {
      console.error("Error al cargar activos:", err);
    } finally {
      setIsLoadingStocks(false);
    }
  };

  // Fetch user data
  const fetchUserData = async () => {
    try {
      const res = await fetch("/api/user");
      const data = await res.json();
      if (data.profile) setUserProfile(data.profile);
      if (data.savedScreeners) setSavedScreeners(data.savedScreeners);
      if (data.watchlist) setWatchlist(data.watchlist);
      if (data.recentAlerts) setRecentAlerts(data.recentAlerts);
    } catch (err) {
      console.error("Error al cargar datos de usuario:", err);
    }
  };

  useEffect(() => {
    fetchStocks();
    fetchUserData();
  }, []);

  useEffect(() => {
    fetchStocks();
  }, [filters]);

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 3500);
  };

  // Scraping Action Handler
  const handleTriggerScrape = async (source?: string, universe?: string) => {
    setIsScraping(true);
    showToast("Ejecutando pipeline de scraping EOD...");

    try {
      const res = await fetch("/api/scrape/trigger", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ source, universe })
      });
      const data = await res.json();
      
      setTimeout(() => {
        setIsScraping(false);
        showToast("Scraping EOD completado: 4,820 cotizaciones actualizadas.");
        fetchStocks();
      }, 1600);
    } catch (err) {
      console.error("Error al disparar scraping:", err);
      setIsScraping(false);
    }
  };

  // Toggle watchlist
  const handleToggleWatchlist = async (ticker: string) => {
    try {
      const res = await fetch("/api/user/watchlist", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ticker })
      });
      const data = await res.json();
      if (data.action === "added") {
        showToast(`${ticker} añadido al Radar de Seguimiento.`);
      } else {
        showToast(`${ticker} eliminado del Radar.`);
      }
      fetchUserData();
    } catch (err) {
      console.error("Error toggling watchlist:", err);
    }
  };

  // Apply saved screener
  const handleApplySavedScreener = (screener: SavedScreener) => {
    if (screener.title.toLowerCase().includes("cup")) {
      setFilters(prev => ({
        ...prev,
        selectedPatterns: ["cup"],
        minRvol: 2.0
      }));
    } else if (screener.title.toLowerCase().includes("cruces")) {
      setFilters(prev => ({
        ...prev,
        goldenCrossRecent: true,
        priceAboveSma200: true
      }));
    }
    setActiveTab("screener");
    showToast(`Estrategia '${screener.title}' cargada en el Screener.`);
  };

  // Save new screener
  const handleSaveNewScreener = async (screenerData: { title: string; category: string; description: string }) => {
    try {
      const res = await fetch("/api/user/screener", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(screenerData)
      });
      const newScreener = await res.json();
      setSavedScreeners(prev => [newScreener, ...prev]);
      showToast(`Screener '${newScreener.title}' guardado con éxito.`);
    } catch (err) {
      console.error("Error saving screener:", err);
    }
  };

  // Delete saved screener
  const handleDeleteScreener = async (id: string) => {
    try {
      await fetch(`/api/user/screener/${id}`, { method: "DELETE" });
      setSavedScreeners(prev => prev.filter(s => s.id !== id));
      showToast("Screener eliminado.");
    } catch (err) {
      console.error("Error deleting screener:", err);
    }
  };

  // Add custom ticker via Admin
  const handleAddCustomTicker = async (stockData: { ticker: string; exchange: string; company: string; sector: string }) => {
    try {
      const res = await fetch("/api/scrape/add-ticker", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(stockData)
      });
      const data = await res.json();
      showToast(`Ticker ${stockData.ticker} registrado para ingesta EOD.`);
      fetchStocks();
    } catch (err) {
      console.error("Error adding custom ticker:", err);
    }
  };

  const handleSelectStock = (stock: StockItem) => {
    setSelectedStock(stock);
    setActiveTab("chart");
  };

  const handleOpenCopilotWithStock = (ticker: string) => {
    setCopilotInitialTicker(ticker);
    setActiveTab("copilot");
  };

  const handleSelectStockByTicker = (ticker: string) => {
    const found = stocks.find(s => s.ticker === ticker);
    if (found) {
      setSelectedStock(found);
      setActiveTab("chart");
    }
  };

  return (
    <div className="min-h-screen bg-[#f5f0e8] text-[#1a1a1a] flex flex-col font-sans selection:bg-[#ffcc00] selection:text-primary">
      {/* Toast Notification */}
      {toastMessage && (
        <div className="fixed bottom-5 right-5 z-50 bg-[#1a1a1a] text-white px-4 py-3 rounded-lg border-2 border-[#ffcc00] shadow-[4px_4px_0px_#ffcc00] flex items-center gap-2 font-mono text-xs animate-bounce">
          <span className="w-2 h-2 rounded-full bg-emerald-400"></span>
          <span>{toastMessage}</span>
        </div>
      )}

      {/* Main Header */}
      <Header
        activeTab={activeTab}
        setActiveTab={setActiveTab}
        onTriggerScrape={() => handleTriggerScrape()}
        isScraping={isScraping}
        totalTickersCount={4820}
      />

      {/* Main App Container */}
      <main className="flex-1 max-w-7xl w-full mx-auto px-4 py-6">
        {activeTab === "screener" && (
          <ScreenerView
            stocks={stocks}
            filters={filters}
            setFilters={setFilters}
            onSelectStock={handleSelectStock}
            onOpenCopilotWithStock={handleOpenCopilotWithStock}
            onToggleWatchlist={handleToggleWatchlist}
            watchlistTickers={watchlist.map(w => w.ticker)}
            onSaveScreenerPrompt={() => {
              setActiveTab("portal");
              showToast("Abierto el panel para registrar este screener.");
            }}
          />
        )}

        {activeTab === "chart" && selectedStock && (
          <InteractiveChartView
            selectedStock={selectedStock}
            stocks={stocks}
            onSelectStock={setSelectedStock}
            onOpenCopilotWithStock={handleOpenCopilotWithStock}
          />
        )}

        {activeTab === "copilot" && (
          <CopilotIAView
            stocks={stocks}
            onSelectStock={handleSelectStock}
            initialTicker={copilotInitialTicker}
          />
        )}

        {activeTab === "admin" && (
          <AdminScrapingView
            onTriggerScrape={(source, universe) => handleTriggerScrape(source, universe)}
            isScraping={isScraping}
            onRecalcPatterns={async () => {
              await fetch("/api/scrape/recalc", { method: "POST" });
              showToast("Patrones e indicadores técnicos recalculados.");
              fetchStocks();
            }}
            onClearCache={async () => {
              await fetch("/api/scrape/clear-cache", { method: "POST" });
              showToast("Caché Redis purgada.");
            }}
            onAddCustomTicker={handleAddCustomTicker}
          />
        )}

        {activeTab === "portal" && (
          <UserPortalView
            profile={userProfile}
            savedScreeners={savedScreeners}
            watchlist={watchlist}
            recentAlerts={recentAlerts}
            onApplySavedScreener={handleApplySavedScreener}
            onDeleteScreener={handleDeleteScreener}
            onRemoveFromWatchlist={handleToggleWatchlist}
            onSelectStockByTicker={handleSelectStockByTicker}
            onSaveNewScreener={handleSaveNewScreener}
          />
        )}
      </main>

      {/* Footer */}
      <footer className="w-full bg-[#eee9e0] border-t-2 border-primary py-4 mt-12 text-xs font-mono text-neutral-600">
        <div className="max-w-7xl mx-auto px-4 flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-2">
            <span className="font-bold text-primary">AlphaPulse Terminal</span>
            <span>·</span>
            <span>Scraping EOD (End of Day) Oficial</span>
            <span>·</span>
            <span>Gemini AI Chartist Engine</span>
          </div>
          <div>
            * Herramienta con fines analíticos y cuantitativos. No constituye asesoramiento financiero regulado.
          </div>
        </div>
      </footer>
    </div>
  );
}
