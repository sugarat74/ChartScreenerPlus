/** Small schematic charts for the educational page. They do not use market data. */
import type { GuideIllustrationKind } from '../i18n/guide/types.ts'

export type ExampleKind = GuideIllustrationKind

type Props = { kind: ExampleKind; label: string }

const INK = '#1a1a1a'
const BLUE = '#0055ff'
const RED = '#e63b2e'
const GREEN = '#059669'

function Drawing({ kind }: Pick<Props, 'kind'>) {
  switch (kind) {
    case 'movingAverages':
      return <>
        <path d="M18 121 L42 110 L63 116 L85 91 L106 101 L130 82 L151 91 L173 65 L197 77 L219 55 L241 67 L263 43 L289 39" stroke={INK} strokeWidth="3" fill="none" />
        <path d="M18 82 H290" stroke={BLUE} strokeWidth="3" fill="none" />
        <path d="M18 102 H290" stroke={RED} strokeWidth="3" fill="none" />
        <path d="M18 119 H290" stroke={GREEN} strokeWidth="3" fill="none" />
        <circle cx="289" cy="39" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
    case 'rsi':
      return <>
        <path d="M18 54 H290 M18 112 H290" stroke={RED} strokeDasharray="5 5" strokeWidth="1.5" />
        <path d="M18 101 L38 91 L58 98 L78 72 L98 79 L118 61 L138 49 L158 60 L178 87 L198 100 L218 79 L238 66 L258 45 L290 37" stroke={BLUE} strokeWidth="3" fill="none" />
        <circle cx="265" cy="43" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
    case 'macd':
      return <>
        <path d="M18 82 H290" stroke={INK} strokeWidth="1.5" />
        {[95, 103, 109, 99, 88, 78, 65, 54, 59, 70, 83, 91, 80, 65, 53, 45].map((bottom, index) => (
          <path key={index} d={`M${28 + index * 16} 82 V${bottom}`} stroke={bottom < 82 ? GREEN : RED} strokeWidth="8" />
        ))}
        <path d="M18 107 C52 111 68 98 95 93 S142 61 171 66 S212 105 240 87 S270 56 290 54" stroke={BLUE} strokeWidth="3" fill="none" />
        <path d="M18 100 C60 106 76 103 105 96 S154 74 180 72 S224 92 245 90 S273 68 290 63" stroke={RED} strokeWidth="3" fill="none" />
      </>
    case 'rvol':
      return <>
        <path d="M18 89 H290" stroke={BLUE} strokeDasharray="6 5" strokeWidth="2" />
        {[113, 105, 121, 96, 109, 101, 116, 92, 108, 98, 112, 102, 96, 45].map((top, index) => (
          <rect key={index} x={25 + index * 19} y={top} width="12" height={135 - top} fill={index === 13 ? RED : INK} />
        ))}
      </>
    case 'pivot':
      return <>
        <path d="M18 61 H290" stroke={BLUE} strokeDasharray="6 5" strokeWidth="2" />
        <path d="M18 119 L42 108 L65 114 L87 86 L109 98 L132 74 L155 87 L176 63 L198 78 L220 69 L242 81 L264 58 L290 37" stroke={INK} strokeWidth="3" fill="none" />
        <circle cx="264" cy="58" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
    case 'volume':
      return <>
        <path d="M18 67 L38 58 L58 64 L78 46 L98 56 L118 50 L138 59 L158 42 L178 55 L198 49 L218 58 L238 38 L258 45 L288 32" stroke={INK} strokeWidth="2.5" fill="none" />
        <path d="M18 88 H290" stroke={INK} strokeWidth="1.5" />
        {[108, 119, 105, 97, 114, 101, 110, 94, 112, 102, 117, 91, 107, 96].map((top, index) => (
          <rect key={index} x={25 + index * 19} y={top} width="12" height={135 - top} fill={index % 3 === 1 ? RED : GREEN} />
        ))}
      </>
    case 'goldenCross':
      return <>
        <path d="M18 70 C75 73 126 76 184 78 S249 79 290 82" stroke={RED} strokeWidth="3" fill="none" />
        <path d="M18 112 C75 107 119 99 168 95 S221 88 243 80 S273 61 290 51" stroke={BLUE} strokeWidth="3" fill="none" />
        <circle cx="249" cy="78" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
    case 'deathCross':
      return <>
        <path d="M18 84 C77 80 129 77 184 75 S250 73 290 70" stroke={RED} strokeWidth="3" fill="none" />
        <path d="M18 41 C77 47 124 56 169 61 S222 68 245 74 S274 94 290 106" stroke={BLUE} strokeWidth="3" fill="none" />
        <circle cx="249" cy="74" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
    case 'bullishAlignment':
      return <>
        <path d="M18 72 C77 68 135 61 190 49 S258 37 290 32" stroke={GREEN} strokeWidth="3" fill="none" />
        <path d="M18 91 C78 88 133 79 190 71 S255 61 290 54" stroke={BLUE} strokeWidth="3" fill="none" />
        <path d="M18 114 C79 112 137 105 190 98 S258 89 290 82" stroke={RED} strokeWidth="3" fill="none" />
        <circle cx="283" cy="33" r="4" fill={GREEN} /><circle cx="283" cy="55" r="4" fill={BLUE} /><circle cx="283" cy="83" r="4" fill={RED} />
      </>
    case 'bearishAlignment':
      return <>
        <path d="M18 72 C76 77 133 87 189 99 S255 112 290 121" stroke={GREEN} strokeWidth="3" fill="none" />
        <path d="M18 57 C77 62 136 68 190 76 S257 87 290 94" stroke={BLUE} strokeWidth="3" fill="none" />
        <path d="M18 38 C77 43 132 48 190 54 S256 64 290 70" stroke={RED} strokeWidth="3" fill="none" />
        <circle cx="283" cy="119" r="4" fill={GREEN} /><circle cx="283" cy="93" r="4" fill={BLUE} /><circle cx="283" cy="69" r="4" fill={RED} />
      </>
    case 'pivotBreakoutRvol':
      return <>
        <path d="M18 60 H290" stroke={BLUE} strokeDasharray="6 5" strokeWidth="2" />
        <path d="M18 74 L45 70 L70 79 L94 65 L119 71 L143 63 L167 74 L191 64 L215 69 L239 66 L263 56 L288 35" stroke={INK} strokeWidth="3" fill="none" />
        <path d="M18 95 H290" stroke={INK} strokeWidth="1" />
        {[123, 120, 125, 119, 122, 121, 124, 117, 122, 120, 123, 118, 99].map((top, index) => (
          <rect key={index} x={26 + index * 20} y={top} width="11" height={135 - top} fill={index === 12 ? GREEN : INK} />
        ))}
        <circle cx="288" cy="35" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
    case 'rsiOverbought':
      return <>
        <path d="M18 54 H290 M18 112 H290" stroke={RED} strokeDasharray="5 5" strokeWidth="1.5" />
        <path d="M18 98 L43 87 L68 97 L92 79 L117 86 L142 69 L167 78 L192 64 L217 70 L242 56 L266 44 L290 39" stroke={BLUE} strokeWidth="3" fill="none" />
        <circle cx="290" cy="39" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
    case 'rsiOversold':
      return <>
        <path d="M18 54 H290 M18 112 H290" stroke={RED} strokeDasharray="5 5" strokeWidth="1.5" />
        <path d="M18 52 L43 63 L68 55 L92 73 L117 65 L142 83 L167 76 L192 94 L217 87 L242 104 L266 118 L290 123" stroke={BLUE} strokeWidth="3" fill="none" />
        <circle cx="290" cy="123" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
    case 'macdBullish':
      return <>
        <path d="M18 82 H290" stroke={INK} strokeWidth="1.5" />
        <path d="M18 73 C77 76 117 78 170 80 S238 79 290 73" stroke={RED} strokeWidth="3" fill="none" />
        <path d="M18 108 C76 105 118 98 167 93 S229 83 247 78 S273 67 290 58" stroke={BLUE} strokeWidth="3" fill="none" />
        <circle cx="247" cy="78" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
    case 'macdBearish':
      return <>
        <path d="M18 82 H290" stroke={INK} strokeWidth="1.5" />
        <path d="M18 91 C77 87 119 84 172 81 S240 79 290 83" stroke={RED} strokeWidth="3" fill="none" />
        <path d="M18 48 C77 55 118 64 167 68 S229 75 247 79 S273 91 290 103" stroke={BLUE} strokeWidth="3" fill="none" />
        <circle cx="247" cy="79" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
    case 'doubleTop':
      return <>
        <path d="M18 104 H290" stroke={BLUE} strokeDasharray="6 5" strokeWidth="2" />
        <path d="M18 125 L48 112 L78 83 L105 42 L130 70 L155 104 L181 75 L207 43 L231 70 L252 101 L278 125 L290 130" stroke={INK} strokeWidth="3" fill="none" />
        <circle cx="105" cy="42" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" /><circle cx="207" cy="43" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
    case 'doubleBottom':
      return <>
        <path d="M18 63 H290" stroke={BLUE} strokeDasharray="6 5" strokeWidth="2" />
        <path d="M18 45 L49 68 L78 109 L105 124 L132 91 L157 63 L183 90 L209 123 L235 100 L258 65 L281 43 L290 35" stroke={INK} strokeWidth="3" fill="none" />
        <circle cx="105" cy="124" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" /><circle cx="209" cy="123" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
    case 'cupWithHandle':
      return <>
        <path d="M223 65 H290" stroke={BLUE} strokeDasharray="6 5" strokeWidth="2" />
        <path d="M18 35 L44 50 C66 93 86 119 131 120 C176 122 201 95 223 51 L241 73 L254 81 L270 65 L289 35" stroke={INK} strokeWidth="3" fill="none" />
        <circle cx="131" cy="120" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" /><circle cx="254" cy="81" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
    case 'bullFlag':
      return <>
        <path d="M18 130 L48 126 L77 109 L107 45 L128 36 L151 55 L175 52 L198 68 L220 63 L241 75 L261 54 L289 30" stroke={INK} strokeWidth="3" fill="none" />
        <path d="M125 37 L245 70 M143 76 L250 108" stroke={BLUE} strokeDasharray="6 5" strokeWidth="2" />
        <circle cx="261" cy="54" r="5" fill="#ffcc00" stroke={INK} strokeWidth="2" />
      </>
  }
}

export default function TechnicalExample({ kind, label }: Props) {
  return (
    <svg role="img" aria-label={label} viewBox="0 0 308 154" className="block h-auto w-full border border-outline-variant bg-surface-bright">
      <path d="M18 35 H290 M18 82 H290 M18 130 H290 M18 20 V138 M154 20 V138 M290 20 V138" stroke="#d0cbc3" strokeWidth="1" />
      <Drawing kind={kind} />
    </svg>
  )
}
