(function(blocks, element, components){
  var el = element.createElement;
  blocks.registerBlockType('lightevents/event-detail', {
    edit: function(props){
      return el('div', {className:'lightevents-editor-card'},
        el('strong', null, 'LightEvents - Détail événement'),
        el(components.TextControl, {label:'ID événement', type:'number', value:props.attributes.id || 0, onChange:function(v){ props.setAttributes({id: parseInt(v || '0', 10)}); }}),
        el(components.ToggleControl, {label:'Afficher réservation/paiement', checked:props.attributes.checkout !== 'false', onChange:function(v){ props.setAttributes({checkout: v ? 'true' : 'false'}); }})
      );
    },
    save: function(){ return null; }
  });
})(window.wp.blocks, window.wp.element, window.wp.components);
