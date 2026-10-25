import type { GuideTexts } from './types.ts'

/**
 * English user manual: a translation of `es.ts` (the source). Keep both in
 * step; every threshold and label must match the code (see `types.ts`).
 */
export const guideEn: GuideTexts = {
  eyebrow: 'User manual',
  title: 'How Chartiko works',
  intro:
    'Chartiko is a technical stock screener for the S&P 500: it filters stocks with technical-analysis criteria, ranks them and lets you review each one on a chart. This manual explains every screen, filter, indicator, signal and pattern you will see.',
  openScreener: 'Open the Screener',
  tocTitle: 'Contents',
  sections: [
    {
      id: 'empezar',
      title: 'Get started in three steps',
      steps: [
        {
          title: 'Filter',
          body: 'On the Screener (the home page) combine technical signals, RSI ranges, relative volume, moving averages and chart patterns to keep only the stocks you care about.',
        },
        {
          title: 'Sort and browse',
          body: 'Choose a "Sort by" order and move through the candidate pages with "Previous" and "Next". You can show 25, 50, 100 or 200 per page.',
        },
        {
          title: 'Review the chart',
          body: 'Click a candidate’s symbol to open its chart with candles, volume, averages, active signals and patterns. With an account you can save your filters and follow stocks.',
        },
      ],
    },
    {
      id: 'datos',
      title: 'What data Chartiko uses',
      paragraphs: [
        'The universe is the S&P 500 (about 500 US stocks). Chartiko works with daily data: one candle per stock and session, with open, high, low, close and volume.',
        'Data is updated once a day, every US market trading day, starting at 4:30 pm New York time, about 30 minutes after the session closes. Indicators, signals, patterns and the list are then recalculated. There are no real-time or intraday quotes.',
        'The date of the latest session is shown on each stock’s page. If a stock has little history (for example fewer than 200 sessions for the SMA 200), that indicator stays empty instead of showing an invented value, and filters that need it leave the stock out.',
      ],
    },
    {
      id: 'listado',
      title: 'The candidate list',
      paragraphs: [
        'Each row is a candidate: a stock that meets every active filter. With no filters you see the whole universe, ranked. Above the table you can read "Showing X–Y of Z candidates".',
      ],
      entries: [
        { term: 'Symbol / Company', summary: 'Ticker, company name and exchange (for example NYSE or NASDAQ). Click the symbol to open its chart.' },
        { term: 'Close', summary: 'Closing price of the latest available session.' },
        { term: 'Chg %', summary: 'Percentage change between the latest close and the previous session’s close. Empty when the stock has a single session.' },
        { term: 'RVOL', summary: 'Relative volume of the latest session (see "Technical indicators"). 2.0× means twice the average volume.' },
        { term: 'RSI (14)', summary: '14-session Relative Strength Index, from 0 to 100 (see "Technical indicators").' },
        {
          term: 'Signals',
          summary: 'Badges for the active technical signals and, with a dashed border, for the active chart patterns with their status ("Forming" or "Confirmed").',
        },
      ],
      notes: [
        'Sort by: pick an order in the selector or click a column heading (click again to reverse it; the ▲/▼ arrow shows the active order). Available orders: "Relative volume (highest RVOL)" (default), "Relative volume (lowest RVOL)", "Confidence (most signals)" and "Confidence (fewest signals)" —the number of active signals, not a probability—, "Daily change" highest or lowest, "RSI" highest or lowest, "Close" highest or lowest, "Company (A–Z)", "Company (Z–A)", "Sector (GICS name, A–Z)", "Sector (GICS name, Z–A)", "Industry (A–Z)", "Industry (Z–A)", "HQ country (code, A–Z)" and "HQ country (code, Z–A)". Stocks without a value always go last and ties are ordered by ticker.',
        'Column filters: the funnel button next to each heading opens its filter: text for Symbol / Company (ticker or name) and Industry, a list for Sector and HQ country, a minimum and maximum (both included) for Close, Chg %, RVOL and RSI, and the same signals and patterns as the technical criteria for Signals. They apply to every candidate, not only the visible page; they show as chips above the table and "Clear filters" removes them too.',
        'Pagination: "Previous" and "Next" move through the pages and "Per page" chooses 25, 50, 100 or 200 candidates. Changing filters, order or page size goes back to page 1.',
        'Filters, order and page are kept in the page address: bookmark or share it and it opens the same way.',
      ],
    },
    {
      id: 'filtros',
      title: 'The Screener filters',
      paragraphs: [
        'All active filters are combined: a candidate must meet every one of them. Within "Technical signals" and "Chart patterns", however, meeting one of the chosen options is enough. "Clear filters" removes every criterion and keeps the chosen order.',
      ],
      entries: [
        {
          term: 'Technical signals',
          summary: 'Shows only stocks with at least one of the selected signals active in the latest session.',
          details: ['The nine signals are explained in "Technical signals".'],
        },
        {
          term: 'RSI (14): Min and Max',
          summary: 'Limits the latest RSI to the values you type (0 to 100, both included). Leave a box empty to leave that end open.',
          details: ['Example: Min 30 and Max 70 excludes oversold and overbought zones.'],
        },
        {
          term: 'Minimum RVOL',
          summary: 'Requires relative volume of at least 1×, 1.5×, 2× or 3×. "All" turns the filter off.',
        },
        {
          term: 'Price > SMA 200',
          summary: 'Shows only stocks whose latest close is above their 200-session simple moving average, a common way to keep stocks in a long-term uptrend.',
        },
        {
          term: 'Moving-average cross',
          summary: '"Bullish" requires the SMA 50 to be above the SMA 200 and "Bearish" below it. "Any" turns the filter off.',
          details: [
            'It describes where the averages are now, not a recent cross. To find the cross of the day use the "Golden cross" or "Death cross" signals.',
          ],
        },
        {
          term: 'Chart patterns',
          summary: 'Shows stocks with an active pattern of the chosen types. The ten types are grouped as bullish (double and triple bottom, cup with handle, bull flag, bullish pennant) and bearish (double and triple top, inverse cup with handle, bear flag, bearish pennant).',
          details: [
            'The status control ("Any", "Forming", "Confirmed") becomes available once a pattern is chosen and only narrows those patterns.',
          ],
        },
        {
          term: 'Sector context: trend',
          summary: '"Uptrend", "Mixed" or "Downtrend" requires that trend in the SPDR sector ETF of each stock’s sector: uptrend when the ETF closes above its SMA 50 and that is above its SMA 200, downtrend the other way round and mixed otherwise. "Any" turns the filter off.',
          details: [
            'It uses the ETF’s latest session. A stock without a sector ETF, or with sector data more than 7 days older than its latest session, never matches. It needs an account.',
          ],
        },
        {
          term: 'Sector context: relative strength',
          summary: '"Top N of 11" only shows stocks whose sector is among the N strongest of the 11 by 3-month return against the S&P 500 (the same ranking as the sector table on the Market page). "All sectors" turns the filter off.',
          details: [
            'A sector without a rank (no relative-strength data) never matches. The sector context filters can be saved in a screener and used in its alerts while the account has access; they need an account.',
          ],
        },
      ],
    },
    {
      id: 'indicadores',
      title: 'Technical indicators',
      paragraphs: [
        'Chartiko computes the indicators from each stock’s daily candles. These are the ones you see in the list, on the chart or behind the signals:',
      ],
      entries: [
        {
          term: 'SMA 20, 50 and 200 (simple moving average)',
          summary: 'Average of the closes of the last 20, 50 or 200 sessions. It smooths the price to show the short (20), medium (50) and long (200) term trend.',
          illustration: { kind: 'movingAverages', alt: 'Black price line and three horizontal simple moving average levels, blue, red and green, shown as current references.' },
          details: [
            'Common reading: price above the SMA 200 suggests a long-term uptrend; the SMA 50 above the SMA 200 too.',
            'On the chart they are drawn as horizontal lines at their current value.',
          ],
        },
        {
          term: 'RSI 14 (Relative Strength Index)',
          summary: 'An oscillator from 0 to 100 comparing the strength of the gains and losses of the last 14 sessions (Wilder’s method).',
          illustration: { kind: 'rsi', alt: 'Blue RSI oscillator crossing an upper area marked by a dashed red line.' },
          details: [
            '70 or more is usually read as overbought and 30 or less as oversold; they are zones to watch, not buy or sell orders.',
          ],
        },
        {
          term: 'MACD 12/26/9',
          summary: 'Difference between the 12-session and 26-session exponential averages (MACD line), together with its 9-session exponential average (signal line). It measures the momentum of the trend.',
          illustration: { kind: 'macd', alt: 'MACD histogram around zero and crossing blue MACD and red signal lines.' },
          details: ['When the MACD line crosses its signal line the "MACD bullish" or "MACD bearish" signals are generated.'],
        },
        {
          term: 'RVOL (relative volume)',
          summary: 'Volume of the latest session divided by the average volume of the previous 50 sessions.',
          illustration: { kind: 'rvol', alt: 'Volume bars with a final red bar well above the dashed blue average.' },
          details: [
            '1× is normal volume; 2× or more shows unusual participation, which often comes with breakouts and news.',
          ],
        },
        {
          term: 'Pivot',
          summary: 'The highest high of the 20 sessions before the latest one. Closing above it is a breakout.',
          illustration: { kind: 'pivot', alt: 'Black price line ending above a horizontal blue pivot level.' },
          details: ['It appears on the chart as the "PIVOT" line when the "Pivot breakout + RVOL" signal is active.'],
        },
        {
          term: 'Volume',
          summary: 'Number of shares traded in each session, shown as bars below the price chart.',
          illustration: { kind: 'volume', alt: 'Price line above a lower panel of green and red volume bars.' },
        },
      ],
      notes: [
        'The illustrations are diagrams with fictional data. They explain the shape of each indicator; they do not show live quotes or current signals.',
        'Chartiko also computes the 21- and 55-session EMAs, the 14-session ADX and Bollinger Bands (20 sessions, 2 deviations) for future features; they are not shown or used in filters today.',
      ],
    },
    {
      id: 'senales',
      title: 'Technical signals',
      paragraphs: [
        'A signal is a technical condition checked with fixed rules on each stock’s latest session. They are recalculated every day: when the condition no longer holds, the signal disappears.',
      ],
      entries: [
        { term: 'Golden cross', summary: 'The SMA 50 crosses above the SMA 200 in the latest session (in the previous one it was equal or below). A classic sign of a turn to an uptrend.', illustration: { kind: 'goldenCross', alt: 'The blue SMA 50 crosses above the red SMA 200 at the highlighted point.' } },
        { term: 'Death cross', summary: 'The SMA 50 crosses below the SMA 200 in the latest session. A classic sign of a turn to a downtrend.', illustration: { kind: 'deathCross', alt: 'The blue SMA 50 crosses below the red SMA 200 at the highlighted point.' } },
        { term: 'Bullish alignment', summary: 'SMA 20 > SMA 50 > SMA 200: the short, medium and long averages are stacked upwards.', illustration: { kind: 'bullishAlignment', alt: 'Three averages stacked from top to bottom: green SMA 20, blue SMA 50 and red SMA 200.' } },
        { term: 'Bearish alignment', summary: 'SMA 20 < SMA 50 < SMA 200: the averages are stacked downwards.', illustration: { kind: 'bearishAlignment', alt: 'Three averages stacked from bottom to top: green SMA 20, blue SMA 50 and red SMA 200.' } },
        { term: 'Pivot breakout + RVOL', summary: 'The close is above the highest high of the previous 20 sessions (the pivot) with relative volume of 2× or more. A breakout with participation.', illustration: { kind: 'pivotBreakoutRvol', alt: 'The latest close rises above the horizontal pivot and the last volume bar stands above the earlier bars, with RVOL of at least 2.' } },
        { term: 'RSI overbought', summary: 'RSI 14 at or above 70.', illustration: { kind: 'rsiOverbought', alt: 'The blue RSI line ends above the upper threshold of 70.' } },
        { term: 'RSI oversold', summary: 'RSI 14 at or below 30.', illustration: { kind: 'rsiOversold', alt: 'The blue RSI line ends below the lower threshold of 30.' } },
        { term: 'MACD bullish', summary: 'The MACD line crosses above its signal line in the latest session.', illustration: { kind: 'macdBullish', alt: 'The blue MACD line crosses above the red signal line at the highlighted point.' } },
        { term: 'MACD bearish', summary: 'The MACD line crosses below its signal line in the latest session.', illustration: { kind: 'macdBearish', alt: 'The blue MACD line crosses below the red signal line at the highlighted point.' } },
      ],
      notes: [
        'If an indicator lacks enough history, the signal that depends on it is not triggered.',
        'The images are diagrams with fictional data; they show signal conditions, not live quotes or current signals.',
      ],
    },
    {
      id: 'patrones',
      title: 'Chart patterns',
      paragraphs: [
        'Patterns are geometric price formations that Chartiko detects automatically with fixed rules on local highs and lows (a high or low is confirmed when 5 sessions surround it on each side, so a pattern appears at the earliest 5 sessions after its last local high or low).',
        '"Forming" means the figure is complete but has not broken its level yet. "Confirmed" means a close broke through the breakout level within the last 10 sessions: above it for bullish patterns, below it for bearish ones (double and triple top, inverse cup with handle, bear flag, bearish pennant). After that, or if the price invalidates the figure, the pattern is no longer shown. A forming pattern also expires if it does not break out in time: 60 sessions after the last bottom or top, a handle longer than 25 sessions or a flag or pennant longer than 20. Chartiko only shows the breakout level: it never computes price targets.',
      ],
      entries: [
        {
          term: 'Double bottom',
          summary: 'Two similar lows (up to 3% apart), 15 to 120 sessions apart, after a fall of at least 15% over the previous 60 sessions that ends at the first low (nothing more than 1.5% lower in the 40 sessions before it), with a rebound of at least 10% between them that is not glued to either low. Neither low may be a lone wick far from the closes.',
          illustration: { kind: 'doubleBottom', alt: 'Price with two similar bottoms and a rise above the intermediate peak level.' },
          details: ['Breakout level: the high between the two bottoms. Confirmed by a close above it; invalidated by any close below the bottoms.'],
        },
        {
          term: 'Double top',
          summary: 'Two similar highs (up to 3% apart), 15 to 120 sessions apart, after a rise of at least 15% over the previous 60 sessions that ends at the first top (nothing more than 1.5% higher in the 40 sessions before it), with a drop of at least 10% between them that is not glued to either top. Neither top may be a lone wick far from the closes.',
          illustration: { kind: 'doubleTop', alt: 'Price with two similar tops and a fall below the intermediate trough level.' },
          details: ['Breakout level: the low between the two tops. Confirmed by a close below it; invalidated by any close above the tops.'],
        },
        {
          term: 'Cup with handle',
          summary: 'A cup of 30 to 325 sessions, 12% to 35% deep, with its low between 20% and 80% of its length, no intermediate peaks above the lower rim and rims of similar height (within 5%), followed by a handle of 5 to 25 sessions that pulls back at most 12%, stays above the middle of the cup and does not rise more than 3% above the right rim.',
          illustration: { kind: 'cupWithHandle', alt: 'Price tracing a rounded base, a short handle and a rise above the handle high.' },
          details: ['Breakout level: the high of the handle. Confirmed by a close above it with relative volume of 1.5× or more; a close above the rim without that volume, or a later close below the handle low, invalidates it.'],
        },
        {
          term: 'Bull flag',
          summary: 'A pole (a rise of at least 15% in 20 sessions or fewer) followed by a flag of 5 to 20 sessions that drifts without rising, on lower volume and without retracing more than half of the pole.',
          illustration: { kind: 'bullFlag', alt: 'Price rising sharply, drifting down within a narrow channel and then breaking upward.' },
          details: ['Breakout level: the high of the flag. Confirmed by a close above it; a later close below the flag low invalidates it.'],
        },
        {
          term: 'Triple bottom',
          summary: 'Three similar lows (up to 3% apart), each 15 to 120 sessions from the next, after a fall of at least 15% that ends at the first low, with two rebounds of at least 10% between them. The same rules as the double bottom apply to each low and rebound.',
          illustration: { kind: 'tripleBottom', alt: 'Price with three similar bottoms and a rise above the higher of the two intermediate peaks.' },
          details: ['Breakout level: the higher of the two peaks between the lows. Confirmed by a close above it; invalidated by any close below the lows. A triple bottom hides the double bottom formed by two of its lows.'],
        },
        {
          term: 'Triple top',
          summary: 'Three similar highs (up to 3% apart), each 15 to 120 sessions from the next, after a rise of at least 15% that ends at the first top, with two drops of at least 10% between them. The same rules as the double top apply to each top and drop.',
          illustration: { kind: 'tripleTop', alt: 'Price with three similar tops and a fall below the lower of the two intermediate troughs.' },
          details: ['Breakout level: the lower of the two troughs between the tops. Confirmed by a close below it; invalidated by any close above the tops. A triple top hides the double top formed by two of its tops.'],
        },
        {
          term: 'Inverse cup with handle',
          summary: 'The mirror of the cup with handle: a rounded top of 30 to 325 sessions, 12% to 35% tall, between two lows of similar height (within 5%), followed by a handle of 5 to 25 sessions that bounces at most 12% and stays below the middle of the cup.',
          illustration: { kind: 'inverseCupWithHandle', alt: 'Price tracing a rounded top, a short upward handle and a fall below the handle low.' },
          details: ['Breakout level: the low of the handle. Confirmed by a close below it with relative volume of 1.5× or more; a later close above the handle high invalidates it.'],
        },
        {
          term: 'Bear flag',
          summary: 'The mirror of the bull flag: a pole (a fall of at least 15% in 20 sessions or fewer) followed by a flag of 5 to 20 sessions that drifts without falling, on lower volume and without retracing more than half of the pole.',
          illustration: { kind: 'bearFlag', alt: 'Price falling sharply, drifting up within a narrow channel and then breaking downward.' },
          details: ['Breakout level: the low of the flag. Confirmed by a close below it; a later close above the flag high invalidates it.'],
        },
        {
          term: 'Bullish pennant',
          summary: 'The bull flag pole followed by a pennant of 5 to 20 sessions that narrows: its second half has a lower high, a higher low and a range of at most 70% of the first half, on lower volume and without retracing more than half of the pole.',
          illustration: { kind: 'bullPennant', alt: 'Price rising sharply, narrowing between two converging lines and then breaking upward.' },
          details: ['Breakout level: the high of the pennant. Confirmed by a close above it; a later close below the pennant low invalidates it. When the same pole also forms a bull flag, only the pennant is shown.'],
        },
        {
          term: 'Bearish pennant',
          summary: 'The bear flag pole followed by a pennant of 5 to 20 sessions that narrows the same way, on lower volume and without retracing more than half of the pole.',
          illustration: { kind: 'bearPennant', alt: 'Price falling sharply, narrowing between two converging lines and then breaking downward.' },
          details: ['Breakout level: the low of the pennant. Confirmed by a close below it; a later close above the pennant high invalidates it. When the same pole also forms a bear flag, only the pennant is shown.'],
        },
      ],
      notes: [
        'The illustrations use fictional data; they are not real detected patterns or price targets.',
        'The parameters of the first four patterns were calibrated against a sample of real charts reviewed by a person; double bottoms, cups and the six newer patterns are still low confidence. Always check the figure on the chart.',
      ],
    },
    {
      id: 'grafico',
      title: 'Each stock’s chart',
      entries: [
        { term: 'Candles', summary: 'Each candle is a session: the body goes from open to close (green if it closed above the open, red if below) and the wicks mark the high and the low.' },
        { term: 'Volume', summary: 'Bars in the lower pane, coloured like each session’s candle.' },
        { term: 'Legend', summary: 'Above the chart: open (O), high (H), low (L), close (C), volume, RSI and RVOL of the latest session, and the SMA 20, 50 and 200 values.' },
        { term: 'Average lines', summary: 'Horizontal lines at the current value of the SMA 20, 50 and 200.' },
        { term: 'PIVOT line', summary: 'Shown when the "Pivot breakout + RVOL" signal is active, at the pivot level.' },
        { term: 'Patterns', summary: 'The key points of each active pattern are marked on the candles and its breakout level is drawn as a dotted line with the pattern name.' },
        { term: '"Active signals" and "Active patterns" panels', summary: 'Below the chart they list every signal and pattern in text with dates and levels, also for screen readers.' },
        {
          term: 'Market context',
          summary: 'When signed in, a stock’s chart shows its sector ETF, the sector trend, its relative strength (rank among the sectors with data) and the market regime above the chart, with a link to the Market page. Each active pattern repeats them.',
          details: [
            'They are the latest session’s data, not the data at the pattern’s date. ETFs do not show it.',
          ],
        },
      ],
      notes: [
        'If averages are missing because the stock has little history, you will see the insufficient-history note; the chart is still shown.',
      ],
    },
    {
      id: 'mercado',
      title: 'Market context',
      paragraphs: [
        'The Market page sums up the US market with fixed, public rules and needs a signed-in account. Each block shows its date ("Data as of…" or, for monthly data, "Data for September 2026"), its source and a "What does it mean?" note. It is descriptive information: it does not say what the market will do and it is not advice.',
      ],
      entries: [
        {
          term: 'Market regime',
          summary: 'A label (Favourable, Neutral, Adverse or Not enough data) that adds up each component: +1, 0 or −1 using fixed thresholds.',
          details: [
            'Components: breadth (60% or more of S&P 500 stocks above their SMA200 adds, 40% or less subtracts), index trend (SPY uptrend adds, downtrend subtracts), sector participation (7 or more of 11 sectors in an uptrend adds, 3 or fewer subtracts), yield curve (10-year – 3-month spread of +0.5 pp or more adds, below 0 subtracts), financial conditions (NFCI below −0.25 adds, above 0 subtracts), net liquidity (+2% or more over 13 weeks adds, −2% or less subtracts) and volatility (VIX below 18 adds, above 28 subtracts).',
            'Favourable with +3 or more, Adverse with −3 or less and Neutral otherwise. With fewer than 4 components with up-to-date data it shows "Not enough data". Stale components and sources that are not enabled are listed with their reason and "does not count". The thresholds are uncalibrated initial values.',
          ],
        },
        {
          term: 'Sectors',
          summary: 'The 11 SPDR sector ETFs ranked by their 3-month return against the S&P 500 (relative strength), with their trend and the share of the sector’s stocks above their SMA200. The 1 week / 1 month / 3 months control only changes the return column; each row opens the ETF chart.',
        },
        { term: 'S&P 500 breadth', summary: 'Share of stocks above their SMA50 and SMA200, new 52-week highs and lows, advancers and decliners, and the SPY trend.' },
        { term: 'Rates and curve', summary: '3-month, 2-year and 10-year Treasury yields, the 10-year change over 20 sessions and the 10-year – 2-year and 10-year – 3-month spreads, calculated by Chartiko. "Inverted" means a negative 10-year – 3-month spread.' },
        { term: 'Fed net liquidity', summary: 'Federal Reserve assets minus the Treasury General Account and reverse repos, every Wednesday, with its 4- and 13-week change.' },
        { term: 'Central banks', summary: 'The Fed funds rate range and the ECB deposit rate, their last change and the last and next meeting from the official calendars, checked by hand.' },
        { term: 'Leading data', summary: 'Initial jobless claims (and their 4-week average), building permits, the OECD leading indicator and the NFCI when its source is enabled.' },
        { term: 'History', summary: 'Each block offers "Show history" with 1- and 3-year charts.' },
      ],
      notes: [
        'A reading is "Stale" when it is older than its frequency allows: 7 days for daily and session data, 16 for weekly and 100 for monthly data. Stale readings are shown but do not count in the regime.',
        'Macroeconomic data come from FRED® and their original sources, cited in each block. "Not available · source not enabled" marks a disabled series.',
      ],
    },
    {
      id: 'cuenta',
      title: 'Your account: saved Screeners and Watchlist',
      paragraphs: [
        'Browsing the Screener and the charts needs no account. With a free account you can also:',
      ],
      entries: [
        { term: 'Saved Screeners', summary: 'Save the current combination of filters and order under a name and apply it again with one click from the Screener or your Portal.' },
        { term: 'Watchlist', summary: 'Click "Follow" on a stock’s chart to add it to your list and see it in your Portal; "Following" means you already follow it.' },
        { term: 'Portal', summary: 'Brings together your saved Screeners and your Watchlist.' },
      ],
    },
  ],
  faqTitle: 'Frequently asked questions',
  faq: [
    {
      question: 'Which market does Chartiko analyse?',
      answer: 'The stocks of the US S&P 500 index, with daily data.',
    },
    {
      question: 'How often is the data updated?',
      answer: 'Once a day, every US market trading day, starting at 4:30 pm New York time, about 30 minutes after the close. There is no real-time or intraday data.',
    },
    {
      question: 'What is the difference between a signal and a pattern?',
      answer: 'A signal is a condition on indicators in the latest session (for example RSI ≥ 70). A pattern is a price figure that forms over weeks or months, with a breakout level.',
    },
    {
      question: 'Does "Confidence (most signals)" mean a probability of success?',
      answer: 'No. It only sorts by the number of active signals of each stock.',
    },
    {
      question: 'Does Chartiko tell me what to buy?',
      answer: 'No. Chartiko shows technical information computed with fixed rules; it is not investment advice or a recommendation.',
    },
  ],
  disclaimer:
    'Chartiko provides automatically generated technical information for information and educational purposes only. It is not investment advice or a personal recommendation. Data may contain errors or delays and past performance does not guarantee future results; investing involves the risk of loss.',
}
