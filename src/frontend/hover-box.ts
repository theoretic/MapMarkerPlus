/**
 * Tooltip that follows the cursor while over a marker (MarkupGoogleMap "useHoverBox", without jQuery).
 * The markup is the developer's; data-top / data-left on its root are the offsets from the cursor.
 */
export class HoverBox {
  private box: HTMLElement;
  private top: number;
  private left: number;
  private move = (e: PointerEvent | MouseEvent) => this.position(e);

  constructor(markup: string) {
    const t = document.createElement('template');
    t.innerHTML = markup.trim();
    const node = t.content.firstElementChild;
    this.box = node instanceof HTMLElement ? node : document.createElement('div');
    this.top = parseInt(this.box.getAttribute('data-top') ?? '-10', 10) || 0;
    this.left = parseInt(this.box.getAttribute('data-left') ?? '15', 10) || 0;
    this.box.classList.add('mmp-hoverbox');
    this.box.style.display = 'none';
    document.body.appendChild(this.box);
  }

  show(text: string, e?: MouseEvent): void {
    if (!text) return;
    const span = document.createElement('span');
    span.textContent = text;
    this.box.replaceChildren(span);
    this.box.style.display = 'block';
    if (e) this.position(e);
    document.addEventListener('pointermove', this.move);
  }

  hide(): void {
    this.box.style.display = 'none';
    document.removeEventListener('pointermove', this.move);
  }

  private position(e: PointerEvent | MouseEvent): void {
    this.box.style.top = `${e.pageY + this.top}px`;
    this.box.style.left = `${e.pageX + this.left}px`;
  }

  destroy(): void {
    this.hide();
    this.box.remove();
  }
}
