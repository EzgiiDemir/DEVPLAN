import React from 'react';

export function MayaAvatar({ 
  src = "/maya.png", // İndirdiğiniz görselin dosya yolu (örn: /assets/maya.png)
  alt = "Maya AI Avatar", 
  size = "medium", // 'small', 'medium', 'large' veya özel boyutlar
  className = "" 
}) {
  // Boyut seçenekleri (Avatarın kullanım alanına göre kolayca ölçeklenmesi için)
  const sizeClasses = {
      small: "w-20 h-20",   
  medium: "w-40 h-40",  
  large: "w-72 h-72",    
  full: "w-full h-auto",
  };

  const selectedSize = sizeClasses[size] || sizeClasses.medium;

  return (
    <div className={`relative inline-block rounded-full overflow-hidden shadow-lg bg-gradient-to-b from-slate-900/10 to-slate-900/30 backdrop-blur-sm border border-orange-500/20 ${selectedSize} ${className}`}>
      {/* Arka plan şeffaf PNG olduğu için arkaya tatlı bir AI ışıltısı/glow efekti */}
      <div className="absolute inset-0 bg-radial from-orange-500/15 via-transparent to-transparent animate-pulse" />
      
      {/* Maya Avatar Görseli */}
      <img
        src={src}
        alt={alt}
        className="w-full h-full object-cover object-top relative z-10 transition-transform duration-300 hover:scale-105"
      />
    </div>
  );
}