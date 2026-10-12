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
        'Sort by: "Relative volume (highest RVOL)" (default), "Confidence (most signals)" —the number of active signals, not a probability—, "Daily change" highest or lowest and "RSI" highest or lowest. Stocks without a value always go last and ties are ordered by ticker.',
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
          summary: 'Shows stocks with an active pattern of the chosen types (double bottom, double top, cup with handle, bull flag).',
          details: [
            'The status control ("Any", "Forming", "Confirmed") becomes available once a pattern is chosen and only narrows those patterns.',
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
        { term: 'Golden cross', summary: 'The SMA 50 crosses above the SMA 200 in the latest session (in the previous one it was equal or below). A classic sign of a turn to an uptrend.' },
        { term: 'Death cross', summary: 'The SMA 50 crosses below the SMA 200 in the latest session. A classic sign of a turn to a downtrend.' },
        { term: 'Bullish alignment', summary: 'SMA 20 > SMA 50 > SMA 200: the short, medium and long averages are stacked upwards.' },
        { term: 'Bearish alignment', summary: 'SMA 20 < SMA 50 < SMA 200: the averages are stacked downwards.' },
        { term: 'Pivot breakout + RVOL', summary: 'The close is above the highest high of the previous 20 sessions (the pivot) with relative volume of 2× or more. A breakout with participation.' },
        { term: 'RSI overbought', summary: 'RSI 14 at or above 70.' },
        { term: 'RSI oversold', summary: 'RSI 14 at or below 30.' },
        { term: 'MACD bullish', summary: 'The MACD line crosses above its signal line in the latest session.' },
        { term: 'MACD bearish', summary: 'The MACD line crosses below its signal line in the latest session.' },
      ],
      notes: [
        'If an indicator lacks enough history, the signal that depends on it is not triggered.',
      ],
    },
    {
      id: 'patrones',
      title: 'Chart patterns',
      paragraphs: [
        'Patterns are geometric price formations that Chartiko detects automatically with fixed rules on local highs and lows (a high or low is confirmed when 5 sessions surround it on each side, so a pattern appears at the earliest 5 sessions after its last local high or low).',
        '"Forming" means the figure is complete but has not broken its level yet. "Confirmed" means a close broke through the breakout level (above it; below it for a double top) within the last 10 sessions. After that, or if the price invalidates the figure, the pattern is no longer shown. A forming pattern also expires if it does not break out in time: 60 sessions after the second bottom or top, a handle longer than 25 sessions or a flag longer than 20. Chartiko only shows the breakout level: it never computes price targets.',
      ],
      entries: [
        {
          term: 'Double bottom',
          summary: 'Two similar lows (up to 3% apart), 15 to 120 sessions apart, after a fall of at least 15% over the previous 60 sessions, with a rebound of at least 10% between them.',
          illustration: { kind: 'doubleBottom', alt: 'Price with two similar bottoms and a rise above the intermediate peak level.' },
          details: ['Breakout level: the high between the two bottoms. Confirmed by a close above it; invalidated by a close more than 3% below the bottoms.'],
        },
        {
          term: 'Double top',
          summary: 'Two similar highs (up to 3% apart), 15 to 120 sessions apart, after a rise of at least 15% over the previous 60 sessions, with a drop of at least 10% between them.',
          illustration: { kind: 'doubleTop', alt: 'Price with two similar tops and a fall below the intermediate trough level.' },
          details: ['Breakout level: the low between the two tops. Confirmed by a close below it; invalidated by a close more than 3% above the tops.'],
        },
        {
          term: 'Cup with handle',
          summary: 'A cup of 30 to 325 sessions, 12% to 35% deep, with its low between 20% and 80% of its length, no intermediate peaks above the lower rim and rims of similar height (within 5%), followed by a handle of 5 to 25 sessions that pulls back at most 12% and stays above the middle of the cup.',
          illustration: { kind: 'cupWithHandle', alt: 'Price tracing a rounded base, a short handle and a rise above the handle high.' },
          details: ['Breakout level: the high of the handle. Confirmed by a close above it with relative volume of 1.5× or more.'],
        },
        {
          term: 'Bull flag',
          summary: 'A pole (a rise of at least 15% in 15 sessions or fewer) followed by a flag of 5 to 20 sessions that drifts without rising, on lower volume and without retracing more than half of the pole.',
          illustration: { kind: 'bullFlag', alt: 'Price rising sharply, drifting down within a narrow channel and then breaking upward.' },
          details: ['Breakout level: the high of the flag. Confirmed by a close above it.'],
        },
      ],
      notes: [
        'The illustrations use fictional data; they are not real detected patterns or price targets.',
        'The rules use initial parameters that will be calibrated over time. Always check the figure on the chart.',
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
      ],
      notes: [
        'If averages are missing because the stock has little history, you will see the insufficient-history note; the chart is still shown.',
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
