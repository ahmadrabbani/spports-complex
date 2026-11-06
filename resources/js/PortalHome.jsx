import React from 'react';
import { ArrowRight, MapPin, Clock3, Users, ShieldCheck, Dumbbell, Waves, CircleDot, Footprints, Trophy, TreePine, CalendarDays, Phone, ChevronRight, UserRound, Building2 } from 'lucide-react';
import { appPath } from './paths';

const sports = [
    ['Gym & fitness', 'Strength, movement and everyday wellbeing', Dumbbell, /gym|fitness/i, 'gym'],
    ['Swimming pools', 'Make a splash in your fitness routine', Waves, /pool|swim/i, 'pool'],
    ['Badminton', 'Find your rhythm on the court', CircleDot, /badminton/i, 'court'],
    ['Squash', 'A fast-paced way to stay active', CircleDot, /squash/i, 'squash'],
    ['Jogging track', 'A little fresh air. A little more energy.', Footprints, /jogg|running/i, 'track'],
    ['Snooker & billiards', 'Slow down and sharpen your game', Trophy, /snooker|billiard/i, 'snooker'],
    ['Table tennis', 'Bring a friend. Start a friendly rally.', CircleDot, /table tennis/i, 'table'],
    ['Basketball', 'Team up and take your best shot', CircleDot, /basketball/i, 'basket'],
];

export default function PortalHome({ data, user, Link, bookingLink }) {
    const complexes = data.complexes || [];
    const canal = complexes.find(c => /canal/i.test(c.name)) || complexes[0];
    const arena = complexes.find(c => /padel|pedal/i.test(c.name));
    const photos = complexes.filter(c => c.image_url);
    const visit = bookingLink(user, canal?.id);
    const availableSports = sports.map(sport => ({ sport, complex: complexes.find(c => c.facilities?.some(f => sport[3].test(f.name))) })).filter(s => s.complex);
    const photo = canal?.image_url;
    return <main className="club-home">
        <section className="club-hero">
            {photo && <img className="club-hero-photo" src={appPath(photo)} alt={canal.name} fetchPriority="high" />}
            <div className="club-hero-shade" />
            <div className="club-hero-content"><span className="club-eyebrow">LDA SPORTS COMPLEXES · LAHORE</span><h1>{canal ? /canal/i.test(canal.name) ? 'CANAL WEST' : canal.name : 'YOUR NEXT MOVE.'}</h1><h2>YOUR CITY. YOUR SPORT.<br />YOUR LIFESTYLE.</h2><p>A place to play, train and feel your best.<br />Discover sports, fitness and recreation at {canal?.name || 'LDA Sports Complexes'}.</p><div className="club-buttons"><a className="club-button gold" href="#membership">Explore Membership <ArrowRight size={18}/></a><Link className="club-button outline" to={visit}>Plan Your Visit <ArrowRight size={18}/></Link></div></div>
            <div className="club-trust">{[[MapPin,'Find your place',canal?.address || 'Sports destinations across Lahore'],[Clock3,'Plan your visit',canal?.opening_hours || 'Contact your complex for timings'],[Users,'A shared passion','Sports for your everyday life'],[ShieldCheck,'Your member portal','Appointments, accounts & challans']].map(([Icon,title,text])=><div key={title}><Icon/><span><b>{title}</b><small>{text}</small></span></div>)}</div>
        </section>

        <section className="club-section" id="facilities"><div className="club-heading"><div><span className="club-eyebrow">OUR FACILITIES</span><h2>EVERYTHING YOU NEED TO<br className="club-desktop-break"/> PLAY, TRAIN AND UNWIND.</h2></div><p>Find your favourite activity across our sports complexes. Explore each location for its available facilities.</p></div>
            <div className="club-sports-grid">{availableSports.map(({sport:[name,description,Icon,,style],complex})=><Link key={name} className="club-sport" to={`/sports-complexes/${complex.slug}`}><div className={`club-sport-art ${style}`}><div className="club-court-lines"/><Icon strokeWidth={1.2}/><span>FIND YOUR GAME</span></div><div className="club-sport-caption"><i><Icon size={21}/></i><div><h3>{name}</h3><p>{description}</p></div><ChevronRight size={15}/></div></Link>)}</div>
            {!availableSports.length && <div className="club-empty">Explore our locations to discover their latest facilities.</div>}
            <div className="club-center"><Link className="club-button soft" to="/sports-complexes">View All Complexes <ArrowRight size={17}/></Link></div>
        </section>

        <section className="club-padel" id="padel"><div className="club-padel-art" aria-hidden="true"><div className="padel-court"><span/><span/><span/></div><div className="club-padel-label"><CircleDot/><span>A LITTLE COMPETITION.<br/><b>A LOT OF CONNECTION.</b></span></div></div><div className="club-padel-copy"><span className="club-eyebrow">{arena ? arena.name : 'MAKE TIME FOR YOUR GAME'}</span><h2>PLAY. CONNECT.<br/>ENJOY.</h2><p>Turn your next visit into a new routine. Discover a place for your game and connect with your local sports community.</p><div className="club-padel-benefits"><span><Users/>Meet & play</span><span><CalendarDays/>Plan a visit</span><span><Trophy/>Find your game</span></div><Link className="club-button gold" to={arena ? `/sports-complexes/${arena.slug}` : '/sports-complexes'}>Explore {arena ? 'the Arena' : 'Our Complexes'} <ArrowRight size={18}/></Link><small>Check facility access and booking arrangements with the complex.</small></div></section>

        <section className="club-lifestyle" id="about"><div><span className="club-eyebrow">MORE THAN A SPORTS COMPLEX</span><h2>A COMPLETE LIFESTYLE DESTINATION.</h2><p>From your first visit to your next personal best. Make more room for movement, recreation and community.</p></div><div className="club-values">{[[TreePine,'Room to unwind'],[Users,'A sense of community'],[ShieldCheck,'Member services'],[MapPin,'Across Lahore'],[CalendarDays,'Easy appointments']].map(([Icon,title])=><div key={title}><Icon/><span>{title}</span></div>)}</div></section>

        <section className="club-section club-membership" id="membership"><div className="club-heading"><div><span className="club-eyebrow">YOUR MEMBERSHIP JOURNEY</span><h2>JOIN A HEALTHIER, MORE ACTIVE YOU.</h2></div><p>Choose your complex. Ask about current plans, eligibility and fees.</p></div><div className="club-membership-grid">{[[UserRound,'Individual membership','Make time for yourself. Explore a routine that works for you.','navy'],[Users,'Family membership','More quality time. Discover membership options for your family.','gold'],[Building2,'Membership enquiries','Speak to your complex about eligibility, facilities and current packages.','light']].map(([Icon,title,text,style])=><Link key={title} to={visit} className={`club-member-card ${style}`}><Icon/><h3>{title}</h3><p>{text}</p><span>Book an appointment <ArrowRight size={18}/></span></Link>)}</div></section>

        <section className="club-section club-photo-section"><div className="club-heading"><div><span className="club-eyebrow">AROUND OUR COMPLEXES</span><h2>A PLACE FOR YOUR NEXT CHAPTER.</h2></div><Link to="/sports-complexes">Explore all locations <ArrowRight size={16}/></Link></div><div className="club-photos">{photos.slice(0,6).map(c=><Link key={c.id} to={`/sports-complexes/${c.slug}`}><img src={appPath(c.image_url)} alt={c.name} loading="lazy"/><span>{c.name}<ArrowRight size={15}/></span></Link>)}</div></section>

        <section className="club-contact" id="contact"><div><MapPin/><span><b>Come and find us</b><small>{canal?.address || canal?.name || 'Lahore, Pakistan'}</small></span></div><div><Clock3/><span><b>Opening hours</b><small>{canal?.opening_hours || 'Contact your complex for current timings'}</small></span></div><div><Phone/><span><b>Let’s talk</b>{canal?.phone ? <a href={`tel:${canal.phone.replace(/[^+\d]/g,'')}`}>{canal.phone}</a> : <Link to={visit}>Book an enquiry appointment</Link>}</span></div><Link className="club-button gold" to="/locations">Get Directions <ArrowRight size={18}/></Link></section>
        <div className="club-mobile-bar"><Link className="club-button gold" to={visit}><CalendarDays size={18}/>Book a Visit</Link><Link className="club-button navy" to="/sports-complexes"><MapPin size={18}/>Our Complexes</Link></div>
    </main>;
}
