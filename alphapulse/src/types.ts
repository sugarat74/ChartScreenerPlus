export interface Candle {
  date: string;
  open: number;
  high: number;
  low: number;
  close: number;
  volume: number;
  sma50?: number;
  sma200?: number;
}

export interface PatternChecklist {
  label: string;
  status: 'VALIDADO' | 'EN PROCESO' | 'FALLIDO';
}

export interface ChartistPattern {
  name: string;
  confidence: number; // 0-100%
  timeframe: string;
  pivotPrice: number;
  targetPrice: number;
  stopLoss: number;
  riskReward: number;
  description: string;
  validated: boolean;
  checklist: PatternChecklist[];
  geometry?: {
    baseDuration: string;
    depthPercent: number;
    breakoutVolumeGainPercent: number;
  };
}

export interface PastSignal {
  pattern: string;
  date: string;
  entry: number;
  gainPercent: number;
  days: number;
}

export interface StockItem {
  ticker: string;
  company: string;
  sector: string;
  exchange: string;
  price: number;
  changePercent: number;
  changeAmount: number;
  volume: number;
  avgVolume: number;
  rvol: number;
  dollarVolume: string;
  marketCap: string;
  beta: number;
  shortFloat: string;
  range52w: { low: number; high: number };
  sma20: number;
  sma50: number;
  sma200: number;
  ema21: number;
  ema55: number;
  rsi14: number;
  adx: number;
  macd: {
    macd: number;
    signal: number;
    hist: number;
    isBullishCross: boolean;
  };
  bollinger: {
    upper: number;
    middle: number;
    lower: number;
    isSqueeze: boolean;
  };
  primaryPattern: ChartistPattern;
  sparkline: number[];
  historicalCandles?: Candle[];
  trackRecord?: PastSignal[];
  aiThesis?: {
    summary: string;
    pros: string[];
    risks: string[];
    score: number;
  };
}

export interface FilterState {
  searchQuery: string;
  universe: string;
  priceAboveSma200: boolean;
  goldenCrossRecent: boolean;
  ema21AboveEma55: boolean;
  pullbackSma50: boolean;
  rsiRange: [number, number];
  macdBullishCross: boolean;
  adxAbove25: boolean;
  minRvol: number;
  selectedPatterns: string[];
  sortBy: 'rvol_desc' | 'confidence_desc' | 'rsi_desc' | 'dolvol_desc' | 'change_desc';
}

export interface SavedScreener {
  id: string;
  title: string;
  category: string;
  description: string;
  hitsCount: number;
  recentHits: string[];
  emailAlert: boolean;
  createdAt: string;
  lastExecuted: string;
  filters?: Partial<FilterState>;
}

export interface WatchlistItem {
  id: string;
  ticker: string;
  company: string;
  sector: string;
  exchange: string;
  closePrice: number;
  changePercent: number;
  distanceToPivot: string;
  pivotReference: string;
  algorithmicCondition: string;
  sparkline: number[];
}

export interface TriggeredAlert {
  id: string;
  ticker: string;
  time: string;
  tag: string;
  badgeType: 'Ruptura' | 'Macro' | 'Soporte' | 'Alerta';
  message: string;
  volumeBoost?: string;
}

export interface ScrapingUniverse {
  id: string;
  name: string;
  symbolCount: number;
  lastSync: string;
  health: string;
  autoScrape: boolean;
}

export interface ScrapingLog {
  id: string;
  timestamp: string;
  level: 'INIT' | 'INFO' | 'SUCCESS' | 'WARN' | 'RETRY' | 'CALC' | 'STAT' | 'TASK' | 'FETCH' | 'ENGINE' | 'REDIS' | 'HEALTH' | 'REG' | 'SYNC';
  message: string;
}

export interface ScrapingTelemetry {
  systemStatus: 'OPERATIVO' | 'SCRAPING' | 'RECALCULANDO';
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

export interface UserProfile {
  name: string;
  email: string;
  plan: string;
  license: string;
  id: string;
  observedCapital: number;
  dailyPnL: number;
  dailyPnLPercent: number;
  availableMargin: number;
  notificationSettings: {
    emailDigest: boolean;
    emailAddress: string;
    telegramWebhook: boolean;
    telegramBot: string;
    browserPush: boolean;
  };
}

export interface CopilotMessage {
  id: string;
  sender: 'user' | 'ai';
  time: string;
  text: string;
  structuredResult?: {
    refinedCount: number;
    totalEvaluated: number;
    universe: string;
    pipelineSteps: string[];
    leadTicker: string;
    selectedTickers: Array<{
      ticker: string;
      company: string;
      score: number;
      pattern: string;
      rvol: string;
      rationale: string;
    }>;
    exclusions: Array<{
      ticker: string;
      reason: string;
    }>;
  };
}
