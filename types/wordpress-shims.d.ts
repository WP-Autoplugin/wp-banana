declare module '@wordpress/element' {
  // Minimal hook/type shims used in this project
  export function useState<T = any>(initial: T): [T, (val: T) => void];
  export function useEffect(effect: () => void | (() => void), deps?: any[]): void;
  export function useMemo<T = any>(factory: () => T, deps?: any[]): T;
  export const Fragment: any;
  export function createElement(type: any, props?: any, ...children: any[]): any;
  export function render(node: any, container: Element | DocumentFragment): void;
  export function createRoot(container: Element | DocumentFragment): { render: (node: any) => void; unmount: () => void };
}

declare module '@wordpress/i18n' {
  export function __(
    text: string,
    domain?: string
  ): string;
  export function _n(
    single: string,
    plural: string,
    number: number,
    domain?: string
  ): string;
  export function sprintf(
    format: string,
    ...args: Array<string | number>
  ): string;
}

declare module '@wordpress/api-fetch' {
  type Middleware = (options: Record<string, any>, next: (options: Record<string, any>) => Promise<any>) => Promise<any>;
  interface APIFetch {
    (options: Record<string, any>): Promise<any>;
    createNonceMiddleware(nonce: string): Middleware;
    use(middleware: Middleware): void;
  }
  const apiFetch: APIFetch;
  export default apiFetch;
}

declare module '@wordpress/components' {
  import * as React from 'react';
  export const Button: React.FC<
    React.ComponentProps<'button'> & {
      isPrimary?: boolean;
      isSecondary?: boolean;
      isBusy?: boolean;
      disabled?: boolean;
      variant?: 'primary' | 'secondary' | string;
    }
  >;
  export const TextControl: React.FC<{ label?: string; value?: string; onChange?: (val: string) => void; placeholder?: string; help?: string; type?: string; disabled?: boolean }>;
  export const TextareaControl: React.FC<{ label?: string; value?: string; onChange?: (val: string) => void; placeholder?: string; help?: string; rows?: number; disabled?: boolean }>;
  export const SelectControl: React.FC<{ label?: string; value?: string; options?: Array<{ label: string; value: string }>; onChange?: (val: string) => void; disabled?: boolean }>;
  export const Panel: React.FC<{ header?: string; children?: React.ReactNode }>;
  export const PanelBody: React.FC<{ title?: string; initialOpen?: boolean; children?: React.ReactNode }>;
  export const Spinner: React.FC<{}>;
  export const Notice: React.FC<{ status?: 'error' | 'warning' | 'success' | 'info'; children?: React.ReactNode; isDismissible?: boolean }>;
  export const Card: React.FC<{ children?: React.ReactNode }>;
  export const CardBody: React.FC<{ children?: React.ReactNode }>;
}
