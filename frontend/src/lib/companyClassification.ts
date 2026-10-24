import type { MessageKey, Translate } from '../i18n/translate.ts'

const sectors: Record<string, MessageKey> = {
  'Communication Services': 'marketContext.sectors.names.communication_services',
  'Consumer Discretionary': 'marketContext.sectors.names.consumer_discretionary',
  'Consumer Staples': 'marketContext.sectors.names.consumer_staples',
  Energy: 'marketContext.sectors.names.energy',
  Financials: 'marketContext.sectors.names.financials',
  'Health Care': 'marketContext.sectors.names.health_care',
  Industrials: 'marketContext.sectors.names.industrials',
  'Information Technology': 'marketContext.sectors.names.information_technology',
  Materials: 'marketContext.sectors.names.materials',
  'Real Estate': 'marketContext.sectors.names.real_estate',
  Utilities: 'marketContext.sectors.names.utilities',
}

const countries: Record<string, MessageKey> = {
  US: 'screener.countries.us',
  IE: 'screener.countries.ie',
  GB: 'screener.countries.gb',
  CH: 'screener.countries.ch',
  BM: 'screener.countries.bm',
  NL: 'screener.countries.nl',
  CA: 'screener.countries.ca',
}

export function sectorLabel(value: string | null, t: Translate): string {
  if (!value) return t('screener.classificationUnknown')
  return sectors[value] ? t(sectors[value]) : value
}

export function countryLabel(value: string | null, t: Translate): string {
  if (!value) return t('screener.classificationUnknown')
  return countries[value] ? t(countries[value]) : value
}
