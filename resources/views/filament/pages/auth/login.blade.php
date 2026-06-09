@extends('filament-panels::pages.auth.login')

@section('content')
    @parent

    <div style="text-align:center; margin-top:16px;">
        <a href="/admin/auth/google/redirect"
           style="display:inline-flex; align-items:center; gap:8px;
                  padding:10px 20px; border:1px solid #dadce0;
                  border-radius:6px; text-decoration:none;
                  color:#3c4043; font-size:14px; background:#fff;">
            <svg width="18" height="18" viewBox="0 0 48 48">
                <path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.2l6.7-6.7C35.7 2.5 30.2 0 24 0 14.8 0 6.9 5.4 3 13.3l7.8 6C12.7 13 17.9 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v8.5h12.7c-.6 3-2.3 5.5-4.8 7.2l7.5 5.8c4.4-4 6.9-10 6.9-17z"/>
                <path fill="#FBBC05" d="M10.8 28.7A14.5 14.5 0 0 1 9.5 24c0-1.6.3-3.2.8-4.7L2.5 13C.9 16.1 0 19.9 0 24s.9 7.9 2.5 11l8.3-6.3z"/>
                <path fill="#34A853" d="M24 48c6.2 0 11.4-2 15.2-5.5l-7.5-5.8c-2 1.4-4.6 2.2-7.7 2.2-6.1 0-11.3-3.5-13.2-8.5l-7.8 6C6.9 42.6 14.8 48 24 48z"/>
            </svg>
            Sign in with Google
        </a>
    </div>
@endsection