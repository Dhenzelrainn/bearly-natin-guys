@extends('layouts.rider')
@section('title','Messages') @section('page-title','Messages')
@section('content')

<div class="page-intro"><div><span class="eyebrow">Communication</span><h2>Logistics, Buyer & Seller Messaging</h2><p>Coordinate operational concerns without leaving the module. Chat changes stay active in the current front-end session.</p></div></div>
<div class="chat-layout"><aside class="chat-list"><div class="chat-search"><input class="field" style="width:100%;min-width:0" placeholder="Search conversations..." data-filter="conversations"></div>
@foreach($conversations as $i=>$c)<button class="conversation {{ $i===0?'is-active':'' }}" data-conversation data-name="{{ $c['name'] }}" data-role="{{ $c['role'] }}" data-filter-row="conversations"><span class="avatar">{{ $c['initials'] }}</span><span class="conversation-copy"><strong>{{ $c['name'] }}</strong><small>{{ $c['role'] }} • {{ $c['time'] }}</small><p>{{ $c['preview'] }}</p></span></button>@endforeach</aside>
<section class="chat-panel"><header class="chat-head"><strong data-chat-name>{{ $conversations[0]['name'] ?? 'Conversation' }}</strong><small data-chat-role>{{ $conversations[0]['role'] ?? '' }}</small></header><div class="message-thread" data-message-thread><div class="message">Hello! This is the front-end conversation preview for this operational channel.</div><div class="message me">Received. I’ll coordinate the next step from here.</div></div><div class="chat-compose"><input class="field" data-chat-input placeholder="Write a message..."><button class="btn btn-primary" data-chat-send><i data-lucide="send"></i>Send</button></div></section></div>

@endsection