import { injectCss } from '../core/assets';

/** Styles shared by all providers (toolbar, pins, clusters, popups, hover box). Kept tiny and inline. */
const CSS = `
.mmp-root{position:relative}
.mmp-toolbar{position:absolute;z-index:5;top:10px;left:10px;display:flex;gap:6px;align-items:center;font:13px/1.2 system-ui,sans-serif}
.mmp-toolbar button,.mmp-toolbar select{background:#fff;color:#222;border:0;border-radius:4px;box-shadow:0 0 0 2px rgba(0,0,0,.12);padding:5px 9px;font:inherit;cursor:pointer;min-height:29px}
.mmp-toolbar button[aria-pressed=true]{background:#222;color:#fff}
.mmp-toolbar button:focus-visible,.mmp-toolbar select:focus-visible{outline:2px solid #1a73e8;outline-offset:1px}
.mmp-pin{width:27px;height:41px;cursor:pointer;line-height:0}
.mmp-icon{display:block;cursor:pointer;max-width:none}
.mmp-cluster{display:flex;align-items:center;justify-content:center;border-radius:50%;background:rgba(229,72,77,.85);color:#fff;font:600 13px/1 system-ui,sans-serif;box-shadow:0 0 0 5px rgba(229,72,77,.3);cursor:pointer;min-width:32px;min-height:32px;padding:0 4px;box-sizing:border-box}
.mmp-popup{position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);background:#fff;color:#222;padding:8px 10px;border-radius:6px;box-shadow:0 2px 10px rgba(0,0,0,.25);min-width:120px;max-width:280px;font:13px/1.4 system-ui,sans-serif;cursor:auto;z-index:10}
.mmp-popup-close{position:absolute;top:2px;right:4px;border:0;background:none;font-size:16px;line-height:1;cursor:pointer;color:#666}
.mmp-hoverbox{position:absolute;pointer-events:none;z-index:9999;white-space:nowrap}
.mmp-error{display:flex;align-items:center;justify-content:center;height:100%;background:#f3f3f3;color:#a00;font:13px system-ui,sans-serif;padding:1em;text-align:center}
`;

export function injectBaseStyles(): void {
  injectCss('mmp-base-css', CSS);
}
