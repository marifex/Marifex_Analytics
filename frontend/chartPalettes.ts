export type ChartPalette = {
  id: string; name: string; type: 'categorical' | 'monochrome' | 'gradient'; colors?: string[];
  baseColor?: string; slotCount?: number; lightnessSpan?: number; areaOpacity: number; revision: number;
  builtIn: boolean; inherited: boolean; readOnly: boolean; isDefault?: boolean;
};
export type PaletteCatalogue = { schemaVersion: 1; default: string; palettes: ChartPalette[]; limit: number };

function hexRgb(hex: string): [number, number, number] { return [1, 3, 5].map(i => Number.parseInt(hex.slice(i, i + 2), 16)) as [number, number, number]; }
function rgbHex(rgb: number[]): string { return `#${rgb.map(value => Math.max(0, Math.min(255, Math.round(value))).toString(16).padStart(2, '0')).join('').toUpperCase()}`; }
function rgbHsl([r8, g8, b8]: number[]): [number, number, number] {
  const [r,g,b] = [r8,g8,b8].map(v => v / 255); const max = Math.max(r,g,b), min = Math.min(r,g,b); let h = 0; const l = (max + min) / 2; const d = max - min;
  if (d) { if (max === r) h = ((g-b)/d + (g < b ? 6 : 0))/6; else if (max === g) h = ((b-r)/d + 2)/6; else h = ((r-g)/d + 4)/6; }
  return [h, d ? d / (1 - Math.abs(2*l - 1)) : 0, l];
}
function hslRgb([h,s,l]: number[]): number[] { const f=(n:number)=>{const k=(n+h*12)%12;return l-s*Math.min(l,1-l)*Math.max(-1,Math.min(k-3,9-k,1));}; return [f(0)*255,f(8)*255,f(4)*255]; }
export function resolvedColors(palette: ChartPalette): string[] {
  if (palette.type !== 'monochrome') return palette.colors ?? [];
  const count = palette.slotCount ?? 6, span = (palette.lightnessSpan ?? 36) / 100, [h,s,l] = rgbHsl(hexRgb(palette.baseColor ?? '#2563EB'));
  return Array.from({length: count}, (_, i) => rgbHex(hslRgb([h, s, Math.max(.08, Math.min(.92, l - span/2 + span*i/Math.max(1,count-1)))])));
}
export function paletteSupports(palette: ChartPalette, slots: number): boolean { return resolvedColors(palette).length >= slots; }
const matrices = {
  protanopia: [[.152286,1.052583,-.204868],[.114503,.786281,.099216],[-.003882,-.048116,1.051998]],
  deuteranopia: [[.367322,.860646,-.227968],[.280085,.672501,.047413],[-.011820,.042940,.968881]],
};
export function simulateColor(hex: string, mode: keyof typeof matrices): string {
  const rgb = hexRgb(hex).map(v => v/255).map(v => v <= .04045 ? v/12.92 : ((v+.055)/1.055)**2.4); const m = matrices[mode];
  const linear = m.map(row => row.reduce((sum, value, i) => sum + value*rgb[i], 0));
  return rgbHex(linear.map(v => Math.max(0,Math.min(1,v))).map(v => (v <= .0031308 ? 12.92*v : 1.055*v**(1/2.4)-.055)*255));
}
export function contrastRatio(a: string, b: string): number {
  const lum=(hex:string)=>{const c=hexRgb(hex).map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4);return .2126*c[0]+.7152*c[1]+.0722*c[2];}; const [x,y]=[lum(a),lum(b)].sort((p,q)=>q-p); return (x+.05)/(y+.05);
}
function lab(hex: string): [number,number,number] {
  const [r,g,b]=hexRgb(hex).map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4); const xyz=[(r*.4124564+g*.3575761+b*.1804375)/.95047,(r*.2126729+g*.7151522+b*.072175), (r*.0193339+g*.119192+b*.9503041)/1.08883]; const f=(v:number)=>v>216/24389?Math.cbrt(v):(24389/27*v+16)/116; const [x,y,z]=xyz.map(f); return [116*y-16,500*(x-y),200*(y-z)];
}
export function deltaE00(aHex: string, bHex: string): number {
  const [L1,a1,b1]=lab(aHex),[L2,a2,b2]=lab(bHex),C1=Math.hypot(a1,b1),C2=Math.hypot(a2,b2),c=(C1+C2)/2,G=.5*(1-Math.sqrt(c**7/(c**7+25**7))),ap1=(1+G)*a1,ap2=(1+G)*a2,cp1=Math.hypot(ap1,b1),cp2=Math.hypot(ap2,b2); const hp=(x:number,y:number)=>{const h=Math.atan2(y,x)*180/Math.PI;return h<0?h+360:h;},h1=hp(ap1,b1),h2=hp(ap2,b2),dL=L2-L1,dC=cp2-cp1,dh=Math.abs(h2-h1)<=180?h2-h1:h2<=h1?h2-h1+360:h2-h1-360,dH=2*Math.sqrt(cp1*cp2)*Math.sin(dh*Math.PI/360),Lm=(L1+L2)/2,Cm=(cp1+cp2)/2,hm=Math.abs(h1-h2)<=180?(h1+h2)/2:(h1+h2<360?(h1+h2+360)/2:(h1+h2-360)/2),T=1-.17*Math.cos((hm-30)*Math.PI/180)+.24*Math.cos(2*hm*Math.PI/180)+.32*Math.cos((3*hm+6)*Math.PI/180)-.2*Math.cos((4*hm-63)*Math.PI/180),Sl=1+.015*(Lm-50)**2/Math.sqrt(20+(Lm-50)**2),Sc=1+.045*Cm,Sh=1+.015*Cm*T,Rt=-2*Math.sqrt(Cm**7/(Cm**7+25**7))*Math.sin(60*Math.exp(-1*((hm-275)/25)**2)*Math.PI/180); return Math.sqrt((dL/Sl)**2+(dC/Sc)**2+(dH/Sh)**2+Rt*(dC/Sc)*(dH/Sh));
}
export function paletteWarnings(palette: ChartPalette, background='#FFFFFF'): string[] {
  const result:string[]=[], colors=resolvedColors(palette); colors.forEach((color,i)=>{if(contrastRatio(color,background)<3)result.push(`Color ${i+1} has less than 3:1 plot contrast.`);}); (['protanopia','deuteranopia'] as const).forEach(mode=>{const simulated=colors.map(color=>simulateColor(color,mode));for(let i=0;i<simulated.length;i++)for(let j=i+1;j<simulated.length;j++)if(deltaE00(simulated[i],simulated[j])<10)result.push(`Colors ${i+1} and ${j+1} may be indistinguishable in ${mode}.`);}); return result;
}
