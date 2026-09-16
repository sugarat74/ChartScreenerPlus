import React, { useState } from "react";
import { UserProfile, SavedScreener, WatchlistItem, TriggeredAlert, StockItem } from "../types";
import { 
  User, 
  Bookmark, 
  Eye, 
  Bell, 
  Plus, 
  Trash2, 
  Play, 
  Star, 
  TrendingUp, 
  Mail, 
  Send, 
  Check, 
  ShieldCheck, 
  ExternalLink,
  Sliders,
  DollarSign
} from "lucide-react";

interface Props {
  profile: UserProfile;
  savedScreeners: SavedScreener[];
  watchlist: WatchlistItem[];
  recentAlerts: TriggeredAlert[];
  onApplySavedScreener: (screener: SavedScreener) => void;
  onDeleteScreener: (id: string) => void;
  onRemoveFromWatchlist: (ticker: string) => void;
  onSelectStockByTicker: (ticker: string) => void;
  onSaveNewScreener: (screener: { title: string; category: string; description: string }) => void;
}

export const UserPortalView: React.FC<Props> = ({
  profile,
  savedScreeners,
  watchlist,
  recentAlerts,
  onApplySavedScreener,
  onDeleteScreener,
  onRemoveFromWatchlist,
  onSelectStockByTicker,
  onSaveNewScreener
}) => {
  const [activeSubTab, setActiveSubTab] = useState<"screeners" | "watchlist" | "alerts" | "notifications">("screeners");
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [newTitle, setNewTitle] = useState("");
  const [newCategory, setNewCategory] = useState("Patrón Geométrico");
  const [newDescription, setNewDescription] = useState("");

  // Notification settings state
  const [emailAlertsEnabled, setEmailAlertsEnabled] = useState(profile.notificationSettings.emailDigest);
  const [telegramEnabled, setTelegramEnabled] = useState(profile.notificationSettings.telegramWebhook);
  const [telegramBot, setTelegramBot] = useState(profile.notificationSettings.telegramBot);
  const [savedSettingsSuccess, setSavedSettingsSuccess] = useState(false);

  const handleCreateScreener = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newTitle.trim()) return;

    onSaveNewScreener({
      title: newTitle,
      category: newCategory,
      description: newDescription || "Screener personalizado con parámetros de volumen y medias móviles."
    });

    setNewTitle("");
    setNewDescription("");
    setShowCreateModal(false);
  };

  const handleSaveNotifications = () => {
    setSavedSettingsSuccess(true);
    setTimeout(() => setSavedSettingsSuccess(false), 2500);
  };

  return (
    <div className="space-y-6">
      {/* User Identity & Account Banner */}
      <div className="bg-white border-2 border-primary rounded-lg p-5 shadow-[4px_4px_0px_#1a1a1a]">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-4">
            <div className="w-14 h-14 rounded-full bg-primary text-white border-2 border-primary flex items-center justify-center font-headline font-black text-2xl shadow-[2px_2px_0px_#ffcc00]">
              {profile.name[0]}
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h2 className="font-headline font-black text-2xl text-primary">{profile.name}</h2>
                <span className="bg-emerald-100 text-emerald-900 border border-emerald-400 font-mono font-bold text-xs px-2 py-0.5 rounded">
                  {profile.plan}
                </span>
                <span className="bg-[#ffcc00] text-primary border border-primary font-mono text-xs font-bold px-1.5 py-0.5 rounded">
                  {profile.id}
                </span>
              </div>
              <p className="text-xs text-neutral-600 font-mono mt-0.5">
                {profile.email} · Suscripción Activa · Acceso total a 4,820 tickers EOD &amp; Gemini Copilot
              </p>
            </div>
          </div>

          {/* Quick Account Metrics */}
          <div className="flex items-center gap-4 text-xs font-mono">
            <div className="bg-[#faf7f2] border border-primary p-2.5 rounded text-right">
              <span className="text-[10px] text-neutral-500 block">CAPITAL OBSERVADO</span>
              <strong className="text-primary text-sm">${profile.observedCapital.toLocaleString()}</strong>
            </div>
            <div className="bg-emerald-50 border border-emerald-300 p-2.5 rounded text-right">
              <span className="text-[10px] text-emerald-800 block">P&amp;L DIARIO EOD</span>
              <strong className="text-emerald-700 text-sm">
                +${profile.dailyPnL.toLocaleString()} (+{profile.dailyPnLPercent}%)
              </strong>
            </div>
          </div>
        </div>

        {/* Sub-Tabs Navigation */}
        <div className="mt-5 pt-4 border-t-2 border-primary flex items-center gap-2 overflow-x-auto no-scrollbar">
          <button
            onClick={() => setActiveSubTab("screeners")}
            className={`flex items-center gap-1.5 px-4 py-2 font-headline font-bold text-xs uppercase tracking-wider rounded border transition-all ${
              activeSubTab === "screeners"
                ? "bg-primary text-white border-primary shadow-[2px_2px_0px_#ffcc00]"
                : "bg-[#f2ede5] text-neutral-700 border-neutral-300 hover:border-primary"
            }`}
          >
            <Bookmark className="w-3.5 h-3.5" />
            <span>Screeners Guardados ({savedScreeners.length})</span>
          </button>

          <button
            onClick={() => setActiveSubTab("watchlist")}
            className={`flex items-center gap-1.5 px-4 py-2 font-headline font-bold text-xs uppercase tracking-wider rounded border transition-all ${
              activeSubTab === "watchlist"
                ? "bg-primary text-white border-primary shadow-[2px_2px_0px_#ffcc00]"
                : "bg-[#f2ede5] text-neutral-700 border-neutral-300 hover:border-primary"
            }`}
          >
            <Eye className="w-3.5 h-3.5" />
            <span>Radar de Compra / Watchlist ({watchlist.length})</span>
          </button>

          <button
            onClick={() => setActiveSubTab("alerts")}
            className={`flex items-center gap-1.5 px-4 py-2 font-headline font-bold text-xs uppercase tracking-wider rounded border transition-all ${
              activeSubTab === "alerts"
                ? "bg-primary text-white border-primary shadow-[2px_2px_0px_#ffcc00]"
                : "bg-[#f2ede5] text-neutral-700 border-neutral-300 hover:border-primary"
            }`}
          >
            <Bell className="w-3.5 h-3.5" />
            <span>Alertas EOD Disparadas ({recentAlerts.length})</span>
          </button>

          <button
            onClick={() => setActiveSubTab("notifications")}
            className={`flex items-center gap-1.5 px-4 py-2 font-headline font-bold text-xs uppercase tracking-wider rounded border transition-all ${
              activeSubTab === "notifications"
                ? "bg-primary text-white border-primary shadow-[2px_2px_0px_#ffcc00]"
                : "bg-[#f2ede5] text-neutral-700 border-neutral-300 hover:border-primary"
            }`}
          >
            <Mail className="w-3.5 h-3.5" />
            <span>Canales de Notificación</span>
          </button>
        </div>
      </div>

      {/* Sub-Tab 1: Saved Screeners */}
      {activeSubTab === "screeners" && (
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <span className="text-xs font-headline font-bold uppercase tracking-wider text-neutral-600">
              Tus Estrategias Cuantitativas &amp; Screeners Personalizados
            </span>
            <button
              onClick={() => setShowCreateModal(true)}
              className="flex items-center gap-1.5 px-3 py-2 bg-[#ffcc00] hover:bg-[#ffd633] text-primary border-2 border-primary rounded font-headline font-bold text-xs uppercase tracking-wider shadow-[2px_2px_0px_#1a1a1a]"
            >
              <Plus className="w-3.5 h-3.5" />
              <span>Crear Nuevo Screener</span>
            </button>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {savedScreeners.map((screener) => (
              <div
                key={screener.id}
                className="bg-white border-2 border-primary rounded-lg p-5 shadow-[4px_4px_0px_#1a1a1a] flex flex-col justify-between"
              >
                <div>
                  <div className="flex items-start justify-between">
                    <span className="bg-[#eee9e0] text-primary text-[10px] font-mono font-bold px-2 py-0.5 rounded border border-neutral-300">
                      {screener.category}
                    </span>
                    <span className="text-[10px] font-mono text-neutral-500">
                      Ejecutado: {screener.lastExecuted}
                    </span>
                  </div>

                  <h3 className="font-headline font-black text-lg text-primary mt-2">
                    {screener.title}
                  </h3>
                  <p className="text-xs text-neutral-600 font-sans mt-1 leading-relaxed">
                    {screener.description}
                  </p>

                  <div className="mt-4 p-3 bg-[#faf7f2] border border-primary rounded space-y-2">
                    <div className="flex items-center justify-between text-xs font-mono">
                      <span className="text-neutral-600">Coincidencias EOD hoy:</span>
                      <strong className="text-primary font-bold text-sm">{screener.hitsCount} activos</strong>
                    </div>

                    <div className="flex flex-wrap items-center gap-1.5 pt-1">
                      {screener.recentHits.map((h) => (
                        <button
                          key={h}
                          onClick={() => onSelectStockByTicker(h)}
                          className="px-2 py-0.5 bg-white hover:bg-neutral-100 text-primary border border-neutral-300 rounded text-[10px] font-mono font-bold"
                        >
                          {h}
                        </button>
                      ))}
                    </div>
                  </div>
                </div>

                <div className="mt-5 pt-3 border-t border-[#eee9e0] flex items-center justify-between">
                  <button
                    onClick={() => onApplySavedScreener(screener)}
                    className="flex items-center gap-1.5 px-3 py-1.5 bg-primary hover:bg-neutral-800 text-white rounded font-headline font-bold text-xs uppercase tracking-wider"
                  >
                    <Play className="w-3 h-3 fill-white" />
                    <span>Ejecutar</span>
                  </button>

                  <button
                    onClick={() => onDeleteScreener(screener.id)}
                    className="p-1.5 text-neutral-400 hover:text-rose-600 rounded transition-colors"
                    title="Eliminar screener"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Sub-Tab 2: Watchlist / Radar */}
      {activeSubTab === "watchlist" && (
        <div className="bg-white border-2 border-primary rounded-lg overflow-hidden shadow-[4px_4px_0px_#1a1a1a]">
          <div className="p-4 bg-[#eee9e0] border-b-2 border-primary flex items-center justify-between">
            <div>
              <h3 className="font-headline font-black text-base text-primary uppercase">
                Radar de Compra Personal (Watchlist de Alta Prioridad)
              </h3>
              <p className="text-xs text-neutral-600 font-mono">
                Monitorización diaria de proximidad a punto de pivote y roturas al cierre EOD.
              </p>
            </div>
            <span className="font-mono text-xs font-bold text-primary">{watchlist.length} Tickers en Radar</span>
          </div>

          <table className="w-full text-left text-xs border-collapse">
            <thead className="bg-[#faf7f2] border-b border-primary font-headline uppercase text-neutral-600 text-[11px]">
              <tr>
                <th className="py-2.5 px-4">Símbolo</th>
                <th className="py-2.5 px-3">Cierre EOD</th>
                <th className="py-2.5 px-3">Var %</th>
                <th className="py-2.5 px-3">Distancia al Pivote</th>
                <th className="py-2.5 px-4">Condición Algorítmica</th>
                <th className="py-2.5 px-3 text-center">Gráfico 7D</th>
                <th className="py-2.5 px-4 text-right">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#eee9e0] font-mono">
              {watchlist.map((item) => {
                const isPositive = item.changePercent >= 0;

                return (
                  <tr key={item.ticker} className="hover:bg-[#faf7f2]">
                    <td className="py-3 px-4 font-bold text-primary">
                      <div className="flex items-center gap-2">
                        <span className="font-headline font-black text-sm">{item.ticker}</span>
                        <span className="text-[10px] text-neutral-500 font-sans">{item.company}</span>
                      </div>
                    </td>

                    <td className="py-3 px-3 font-bold text-primary">
                      ${item.closePrice.toFixed(2)}
                    </td>

                    <td className="py-3 px-3">
                      <span className={`font-bold px-1.5 py-0.5 rounded text-[11px] ${
                        isPositive ? "bg-emerald-50 text-emerald-800" : "bg-rose-50 text-rose-800"
                      }`}>
                        {isPositive ? `+${item.changePercent.toFixed(2)}%` : `${item.changePercent.toFixed(2)}%`}
                      </span>
                    </td>

                    <td className="py-3 px-3 text-neutral-700">
                      <div>{item.distanceToPivot}</div>
                      <span className="text-[10px] text-neutral-400">{item.pivotReference}</span>
                    </td>

                    <td className="py-3 px-4">
                      <span className="bg-[#ffcc00] text-primary px-2 py-0.5 rounded text-[11px] font-bold border border-primary">
                        {item.algorithmicCondition}
                      </span>
                    </td>

                    <td className="py-3 px-3 text-center">
                      <svg className="w-16 h-5 mx-auto" viewBox="0 0 64 20">
                        <polyline
                          fill="none"
                          stroke={isPositive ? "#10b981" : "#ef4444"}
                          strokeWidth="1.8"
                          points={item.sparkline
                            .map((val, idx) => {
                              const min = Math.min(...item.sparkline);
                              const max = Math.max(...item.sparkline);
                              const range = max - min || 1;
                              const x = (idx / (item.sparkline.length - 1)) * 64;
                              const y = 18 - ((val - min) / range) * 16;
                              return `${x},${y}`;
                            })
                            .join(" ")}
                        />
                      </svg>
                    </td>

                    <td className="py-3 px-4 text-right">
                      <div className="flex items-center justify-end gap-2">
                        <button
                          onClick={() => onSelectStockByTicker(item.ticker)}
                          className="px-2 py-1 bg-primary text-white hover:bg-neutral-800 rounded font-headline font-bold text-[10px] uppercase"
                        >
                          Ver Gráfico
                        </button>
                        <button
                          onClick={() => onRemoveFromWatchlist(item.ticker)}
                          className="p-1 text-neutral-400 hover:text-rose-600 rounded"
                          title="Eliminar de seguimiento"
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* Sub-Tab 3: Triggered Alerts */}
      {activeSubTab === "alerts" && (
        <div className="bg-white border-2 border-primary rounded-lg p-5 shadow-[4px_4px_0px_#1a1a1a] space-y-4">
          <div className="border-b-2 border-primary pb-3 flex items-center justify-between">
            <div>
              <h3 className="font-headline font-black text-lg text-primary uppercase">
                Registro de Alertas EOD Disparadas
              </h3>
              <p className="text-xs text-neutral-600 font-mono">
                Eventos confirmados en el cálculo posterior al cierre oficial del mercado (21:00 CET).
              </p>
            </div>
            <Bell className="w-5 h-5 text-amber-500" />
          </div>

          <div className="space-y-3 font-mono text-xs">
            {recentAlerts.map((al) => (
              <div
                key={al.id}
                className="p-4 bg-[#faf7f2] border-2 border-primary rounded-lg flex flex-wrap items-start justify-between gap-3 shadow-[2px_2px_0px_#1a1a1a]"
              >
                <div className="flex items-start gap-3">
                  <span className="w-8 h-8 rounded bg-primary text-white flex items-center justify-center font-bold text-xs shrink-0">
                    {al.ticker}
                  </span>
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="font-headline font-black text-sm text-primary">{al.ticker}</span>
                      <span className="bg-[#ffcc00] text-primary text-[10px] font-bold px-1.5 py-0.2 rounded border border-primary">
                        {al.tag}
                      </span>
                      {al.volumeBoost && (
                        <span className="bg-emerald-100 text-emerald-800 text-[10px] font-bold px-1.5 py-0.2 rounded">
                          Volumen: {al.volumeBoost}
                        </span>
                      )}
                      <span className="text-neutral-400 text-[11px]">{al.time}</span>
                    </div>
                    <p className="text-xs text-neutral-700 font-sans mt-1">
                      {al.message}
                    </p>
                  </div>
                </div>

                <button
                  onClick={() => onSelectStockByTicker(al.ticker)}
                  className="px-3 py-1.5 bg-primary hover:bg-neutral-800 text-white rounded font-headline font-bold text-[10px] uppercase tracking-wider"
                >
                  Inspeccionar
                </button>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Sub-Tab 4: Notification Settings */}
      {activeSubTab === "notifications" && (
        <div className="bg-white border-2 border-primary rounded-lg p-5 shadow-[4px_4px_0px_#1a1a1a] max-w-2xl space-y-5">
          <div className="border-b-2 border-primary pb-3">
            <h3 className="font-headline font-black text-lg text-primary uppercase">
              Canales de Distribución de Alertas EOD
            </h3>
            <p className="text-xs text-neutral-600 font-mono">
              Recibe automáticamente el resumen de rupturas y cruces dorados nada más finalizar el scraping nocturno.
            </p>
          </div>

          <div className="space-y-4 text-xs font-mono">
            {/* Email Digest */}
            <div className="p-4 bg-[#faf7f2] border-2 border-primary rounded-lg space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Mail className="w-4 h-4 text-primary" />
                  <span className="font-headline font-bold text-sm text-primary">Email Digest EOD (21:15 UTC)</span>
                </div>
                <input
                  type="checkbox"
                  checked={emailAlertsEnabled}
                  onChange={(e) => setEmailAlertsEnabled(e.target.checked)}
                  className="w-4 h-4 accent-primary cursor-pointer"
                />
              </div>
              <p className="text-neutral-600 font-sans text-xs">
                Envío diario con la lista de activos que confirmaron ruptura con volumen superior al 2.0x RVOL.
              </p>
              <input
                type="email"
                defaultValue={profile.email}
                className="w-full p-2 bg-white border border-primary rounded text-xs"
              />
            </div>

            {/* Telegram Bot Webhook */}
            <div className="p-4 bg-[#faf7f2] border-2 border-primary rounded-lg space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Send className="w-4 h-4 text-[#0088cc]" />
                  <span className="font-headline font-bold text-sm text-primary">Telegram Bot Webhook Instantáneo</span>
                </div>
                <input
                  type="checkbox"
                  checked={telegramEnabled}
                  onChange={(e) => setTelegramEnabled(e.target.checked)}
                  className="w-4 h-4 accent-primary cursor-pointer"
                />
              </div>
              <p className="text-neutral-600 font-sans text-xs">
                Mensaje directo a tu canal o chat privado de Telegram con gráficos e indicadores adjuntos.
              </p>
              <input
                type="text"
                value={telegramBot}
                onChange={(e) => setTelegramBot(e.target.value)}
                placeholder="@TuBotTelegram o Chat ID"
                className="w-full p-2 bg-white border border-primary rounded text-xs"
              />
            </div>

            <div className="flex items-center justify-between pt-2">
              <button
                onClick={handleSaveNotifications}
                className="px-5 py-2.5 bg-[#ffcc00] hover:bg-[#ffd633] text-primary border-2 border-primary rounded font-headline font-bold text-xs uppercase tracking-wider shadow-[2px_2px_0px_#1a1a1a]"
              >
                Guardar Preferencias
              </button>

              {savedSettingsSuccess && (
                <span className="text-emerald-700 font-bold flex items-center gap-1">
                  <Check className="w-4 h-4" /> Preferencias actualizadas correctamente
                </span>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Modal: Create Custom Screener */}
      {showCreateModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-xs">
          <div className="bg-white border-2 border-primary rounded-lg max-w-md w-full p-6 shadow-[6px_6px_0px_#1a1a1a] space-y-4">
            <div className="flex items-center justify-between border-b-2 border-primary pb-3">
              <h3 className="font-headline font-black text-lg text-primary uppercase">
                Nuevo Screener Personalizado
              </h3>
              <button
                onClick={() => setShowCreateModal(false)}
                className="w-7 h-7 bg-[#eee9e0] hover:bg-[#e2ddd4] border border-primary rounded flex items-center justify-center font-bold text-xs"
              >
                ✕
              </button>
            </div>

            <form onSubmit={handleCreateScreener} className="space-y-3 text-xs font-mono">
              <div>
                <label className="font-bold text-neutral-700 block mb-1">Nombre de la Estrategia:</label>
                <input
                  type="text"
                  required
                  value={newTitle}
                  onChange={(e) => setNewTitle(e.target.value)}
                  placeholder="Ej. Breakout Cup & Handle con RVOL > 3x"
                  className="w-full p-2 bg-[#faf7f2] border-2 border-primary rounded focus:outline-none focus:bg-white"
                />
              </div>

              <div>
                <label className="font-bold text-neutral-700 block mb-1">Categoría Técnica:</label>
                <select
                  value={newCategory}
                  onChange={(e) => setNewCategory(e.target.value)}
                  className="w-full p-2 bg-[#faf7f2] border-2 border-primary rounded font-bold cursor-pointer"
                >
                  <option value="Patrón Geométrico">Patrón Geométrico (Cup, Flags...)</option>
                  <option value="Tendencia Macro">Tendencia Macro (Golden Cross 50/200)</option>
                  <option value="Momentum & RVOL">Momentum &amp; Volumen Institucional</option>
                  <option value="Mean Reversion">Mean Reversion (RSI Sobrevendido)</option>
                </select>
              </div>

              <div>
                <label className="font-bold text-neutral-700 block mb-1">Descripción / Reglas de Entrada:</label>
                <textarea
                  rows={3}
                  value={newDescription}
                  onChange={(e) => setNewDescription(e.target.value)}
                  placeholder="Reglas: Precio > SMA 200, RVOL > 2.0x, corte alcista de pivote..."
                  className="w-full p-2 bg-[#faf7f2] border-2 border-primary rounded focus:outline-none focus:bg-white resize-none"
                />
              </div>

              <div className="pt-2 flex items-center justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setShowCreateModal(false)}
                  className="px-3 py-2 bg-[#eee9e0] border border-primary rounded font-headline font-bold text-xs"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-primary hover:bg-neutral-800 text-white border-2 border-primary rounded font-headline font-bold text-xs uppercase tracking-wider shadow-[2px_2px_0px_#ffcc00]"
                >
                  Guardar Estrategia
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};
