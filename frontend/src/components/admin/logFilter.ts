/**
 * Log filter options for the admin run log. Kept out of the component file so
 * the component module only exports components (Fast Refresh friendly).
 */
export const LOG_FILTERS = ['ALL', 'PROCESSING', 'SUCCESS', 'FAILED'] as const

export type LogFilter = (typeof LOG_FILTERS)[number]
