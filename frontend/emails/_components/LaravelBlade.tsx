import React from 'react'

interface LaravelBladeProps {
  children?: React.ReactNode;
  variable?: string;
  translationKey?: string;
  fallback?: React.ReactNode;
  html?: boolean;
}

/**
 * LaravelBlade component for handling Laravel's Blade syntax in React Email
 * 
 * @example
 * // Basic usage with Blade variable
 * <LaravelBlade variable="user->name">John Doe</LaravelBlade>
 * 
 * @example
 * // With translation key
 * <LaravelBlade translationKey="welcome">Welcome</LaravelBlade>
 * 
 * @example
 * // HTML content
 * <LaravelBlade variable="user->html_content" html>Default content</LaravelBlade>
 */
export function LaravelBlade({
  children,
  variable,
  fallback,
  html = false
}: LaravelBladeProps) {
  // Use Blade syntax when exporting for Laravel (BLADE_OUTPUT=true) or in production build
  const useBladeSyntax =
    process.env.BLADE_OUTPUT === 'true' || process.env.NODE_ENV === 'production';

  if (!useBladeSyntax) {
  /*   if (translationKey) {
      return <>{children ?? null}</>;
    } */
    return <>{fallback ?? children ?? null}</>;
  }
  
  // In production, use Blade syntax
  if (variable) {
    // dangerouslySetInnerHTML is needed to prevent React from escaping curly braces
    if (html) {
      return <span className={`inline`} dangerouslySetInnerHTML={{ __html: `{!!$${variable}!!}` }} />;
    }
    return <span className={`inline`} dangerouslySetInnerHTML={{ __html: `{{$${variable}}}` }} />;
  }
  
  // For translations: Laravel __('email::email.{key}')
/*   if (translationKey) {
    return <span className="inline" dangerouslySetInnerHTML={{ __html: `{{ __('email::email.${translationKey}') }}` }} />;
  } */

  return <>{children}</>;
}

/**
 * Shorthand component for blade echo statements
 */
export function Blade({ 
  variable, 
  html = false, 
  fallback 
}: { 
  variable: string, 
  html?: boolean, 
  fallback?: React.ReactNode 
}) {
  return <LaravelBlade variable={variable} html={html}>{fallback}</LaravelBlade>;
}

/**
 * Component for blade translations. Pass direct text as children (used in dev/preview);
 * translationKey is used in production for Laravel __('email::email.{key}').
 */
export function BladeTrans({
  translationKey,
  children
}: {
  translationKey: string;
  children?: React.ReactNode;
}) {
  return <LaravelBlade translationKey={translationKey}>{children}</LaravelBlade>;
}

export default LaravelBlade;