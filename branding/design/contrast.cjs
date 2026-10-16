// Prints WCAG 2 contrast ratios: node contrast.cjs 15202b:f2f4f7 [fg:bg ...]
const L=h=>{h=h.replace('#','');const c=[0,2,4].map(i=>parseInt(h.slice(i,i+2),16)/255).map(v=>v<=0.03928?v/12.92:((v+0.055)/1.055)**2.4);return 0.2126*c[0]+0.7152*c[1]+0.0722*c[2]};
const R=(a,b)=>{const x=L(a),y=L(b);return ((Math.max(x,y)+0.05)/(Math.min(x,y)+0.05)).toFixed(2)};
const pairs=process.argv.slice(2);for(const p of pairs){const [a,b]=p.split(':');console.log(a,'on',b,R('#'+a,'#'+b));}
